<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Prefork supervisor for Volucer's `attach` backend.
 *
 * Listens on the relay and forks one child per connection. Requires Unix and `pcntl`.
 * Initialize the application before `serve()` and open persistent connections lazily
 * in each child.
 *
 * If PHP loads `grpc`, set `GRPC_ENABLE_FORK_SUPPORT=1` in the environment before
 * startup; see {@see Supervisor::grpcForkHazard()}.
 */
final class Supervisor
{
    /** The `unix:` prefix volucer's configuration uses for a path relay. */
    public const UNIX_PREFIX = 'unix:';

    /** Environment variable shared by the attach server and supervisor for the relay address. */
    public const RELAY_ENV = 'VOLUCER_RELAY_SOCKET';

    /** Resolve the relay from the argument or environment, returning null if unset. */
    public static function relay(?string $relay = null): ?string
    {
        if ($relay !== null) {
            $relay = trim($relay);

            if ($relay !== '') {
                return $relay;
            }
        }

        $address = getenv(self::RELAY_ENV);

        if (!is_string($address)) {
            return null;
        }

        $address = trim($address);

        return $address === '' ? null : $address;
    }

    /** Maximum time accept waits before the supervisor reaps children, in seconds. */
    private const ACCEPT_TIMEOUT = 0.5;

    /** How long to wait when every child slot is busy, in microseconds. */
    private const SLOT_BACKOFF_US = 5_000;

    /** How long the children already serving get to finish when the master is asked to stop. */
    private const DRAIN_TIMEOUT = 5.0;

    /**
     * The filesystem path of a `unix:` relay, or `null` for a TCP one.
     */
    public static function relayPath(string $relay): ?string
    {
        $relay = trim($relay);

        if (!str_starts_with($relay, self::UNIX_PREFIX)) {
            return null;
        }

        return substr($relay, strlen(self::UNIX_PREFIX));
    }

    /**
     * Bind and listen on a relay, removing a stale Unix socket file first.
     *
     * @return resource
     */
    public static function listen(string $relay)
    {
        $path = self::relayPath($relay);

        if ($path !== null) {
            @unlink($path);

            $listener = @stream_socket_server(
                'unix://' . $path,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );

            if ($listener === false) {
                throw new \RuntimeException(sprintf(
                    'cannot listen on %s: %s (%d)',
                    $path,
                    (string) $errstr,
                    (int) $errno,
                ));
            }

            return $listener;
        }

        [$host, $port] = self::tcpAddress($relay);

        // `so_reuseaddr` so a restarted supervisor is not held off by the previous run's
        // TIME_WAIT, and a backlog large enough that volucer's `workers` dials all land in the
        // queue even before the master has accepted the first of them.
        //
        // `tcp_nodelay` is set on the *listener* rather than on each accepted connection, and that
        // is a small but real asymmetry with [`Worker::connect`]: an accepted socket inherits the
        // listen socket's options, so one option here covers every child. It matters for the same
        // measured reason — 38.96 ms per request against 0.06 ms, because a frame is a header write
        // followed by a payload write and Nagle holds the second until the first is acknowledged.
        // A `unix:` relay, which is what the documentation recommends, has no Nagle to disable.
        $context = stream_context_create([
            'socket' => [
                'so_reuseaddr' => true,
                'backlog' => 128,
                'tcp_nodelay' => true,
            ],
        ]);

        $listener = @stream_socket_server(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($listener === false) {
            throw new \RuntimeException(sprintf(
                'cannot listen on %s:%d: %s (%d)',
                $host,
                $port,
                (string) $errstr,
                (int) $errno,
            ));
        }

        return $listener;
    }

    /**
     * Listen on the relay and fork a child for each accepted connection.
     *
     * The handler matches {@see Worker::run()}. `$children` limits concurrent
     * children; zero means unlimited. Do not set it below the server's worker
     * count, or some startup connections will remain unaccepted.
     *
     * A null relay uses `VOLUCER_RELAY_SOCKET`. SIGTERM and SIGINT stop accepting
     * connections and give active children a bounded time to finish.
     */
    public static function serve(?string $relay, callable $handler, int $children = 0): void
    {
        if ($children < 0) {
            throw new \InvalidArgumentException(sprintf(
                'children must be 0 (no cap) or greater, got %d',
                $children,
            ));
        }

        self::requireFork();

        $relay = Supervisor::relay($relay);

        if ($relay === null) {
            throw new \RuntimeException(sprintf(
                'no relay to listen on: pass one to Supervisor::serve(), or set %s — the same '
                . 'variable the server reads for its `relay`, e.g. "unix:/run/app/workers.sock"',
                self::RELAY_ENV,
            ));
        }

        // Warn rather than abort; callers can enforce a hard failure if needed.
        $hazard = self::grpcForkHazard();
        if ($hazard !== null) {
            @fwrite(STDERR, '[volucer] warning: ' . $hazard . "\n");
        }

        $limit = $children > 0 ? $children : null;
        $listener = self::listen($relay);

        // Load SDK classes before forking so children inherit the initialized class table.
        self::warm();

        $stopping = false;
        self::installSignals(static function () use (&$stopping): void {
            $stopping = true;
        });

        self::announce($relay, $limit);

        /** @var array<int, true> $live */
        $live = [];

        try {
            while (!$stopping) {
                self::reap($live);

                if ($limit !== null && count($live) >= $limit) {
                    // Wait for a child slot before accepting another connection.
                    usleep(self::SLOT_BACKOFF_US);

                    continue;
                }

                $connection = @stream_socket_accept($listener, self::ACCEPT_TIMEOUT);
                if ($connection === false) {
                    // Retry after timeout or signal; the next iteration reaps exited children.
                    continue;
                }

                $pid = pcntl_fork();

                if ($pid === -1) {
                    // Close the connection so the server can replace this worker slot.
                    fclose($connection);

                    continue;
                }

                if ($pid !== 0) {
                    // Close the master's copy so the child can signal EOF on exit.
                    fclose($connection);
                    $live[$pid] = true;

                    continue;
                }

                // --- the child ---

                fclose($listener);

                // Restore default signal handling so service-manager signals stop the child.
                self::restoreSignals();

                self::serveOne($connection, $handler);
            }
        } finally {
            @fclose($listener);

            $path = self::relayPath($relay);
            if ($path !== null) {
                @unlink($path);
            }

            self::drain($live);
        }
    }

    /**
     * Serve one connection in a child and exit.
     *
     * Discard inherited output buffers to avoid flushing the master's output.
     *
     * @param resource $connection
     */
    private static function serveOne($connection, callable $handler): void
    {
        $code = 0;

        try {
            stream_set_blocking($connection, true);

            Worker::create($connection, $connection)->run($handler);
        } catch (\Throwable $error) {
            $code = 1;

            // Keep diagnostics off stdout, which carries protocol frames.
            @fwrite(STDERR, sprintf(
                "[volucer] worker exited: %s: %s\n",
                $error::class,
                $error->getMessage(),
            ));
        } finally {
            @fclose($connection);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            exit($code);
        }
    }

    /**
     * Reap exited children without blocking and remove them from `$live`.
     *
     * @param array<int, true> $live
     */
    private static function reap(array &$live): void
    {
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid <= 0) {
                return;
            }

            unset($live[$pid]);
        }
    }

    /**
     * Give active children a bounded time to finish during shutdown.
     *
     * @param array<int, true> $live
     */
    private static function drain(array &$live): void
    {
        $deadline = microtime(true) + self::DRAIN_TIMEOUT;

        while ($live !== [] && microtime(true) < $deadline) {
            self::reap($live);

            if ($live !== []) {
                usleep(self::SLOT_BACKOFF_US);
            }
        }
    }

    /** Install signal handlers that ask the accept loop to stop. */
    private static function installSignals(callable $stop): void
    {
        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, static function () use ($stop): void {
                $stop();
            });
        }
    }

    /** Leave the child with the default disposition, so a signal to the group still stops it. */
    private static function restoreSignals(): void
    {
        foreach ([SIGTERM, SIGINT] as $signal) {
            @pcntl_signal($signal, SIG_DFL);
        }
    }

    /** Print readiness to stderr, leaving stdout available for protocol output. */
    private static function announce(string $relay, ?int $children): void
    {
        @fwrite(STDERR, sprintf(
            "[volucer] listening on %s (%s)\n",
            $relay,
            $children === null ? 'no child cap' : $children . ' children at most',
        ));
    }

    /** Require the pcntl functions needed to fork and manage children. */
    private static function requireFork(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'pcntl_signal', 'pcntl_async_signals'] as $function) {
            if (\function_exists($function)) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Volucer\Supervisor needs the pcntl extension (%s is missing, and pcntl does not '
                . 'exist on Windows); use a `worker` or `upstream` backend there, or run the '
                . 'supervisor on a PHP built with ext-pcntl',
                $function,
            ));
        }
    }

    /**
     * Describe the gRPC fork hazard, or return null when it does not apply.
     *
     * When the extension is loaded, `GRPC_ENABLE_FORK_SUPPORT=1` must be set
     * before PHP starts; setting it from PHP is too late.
     */
    public static function grpcForkHazard(): ?string
    {
        if (!\extension_loaded('grpc')) {
            return null;
        }

        // The extension reads this environment setting before its threads start.
        $enabled = getenv('GRPC_ENABLE_FORK_SUPPORT');
        if ($enabled !== false && $enabled !== '' && $enabled !== '0') {
            return null;
        }

        return 'this PHP loads grpc and GRPC_ENABLE_FORK_SUPPORT is not set, so every forked child '
            . 'will deadlock in exit() and never be reaped; start the supervisor with '
            . 'GRPC_ENABLE_FORK_SUPPORT=1 in its environment, or run it on a PHP without grpc';
    }

    /** Load the SDK's own classes before the fork, so no child compiles them for itself. */
    private static function warm(): void
    {
        foreach ([
            Worker::class,
            Request::class,
            Response::class,
            Frame::class,
            Header::class,
            MessageType::class,
            ProtocolException::class,
        ] as $class) {
            class_exists($class);
        }
    }

    /**
     * Split a `host:port` relay.
     *
     * An empty host means loopback, which is what a relay without an interface is asking for.
     *
     * @return array{0: string, 1: int}
     */
    private static function tcpAddress(string $relay): array
    {
        $separator = strrpos($relay, ':');

        if ($separator === false) {
            throw new \RuntimeException(sprintf(
                'a relay is either "unix:/path" or "host:port", got "%s"',
                $relay,
            ));
        }

        $host = substr($relay, 0, $separator);
        $port = (int) substr($relay, $separator + 1);

        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException(sprintf('a relay port must be 1..65535, got "%s"', $relay));
        }

        return [$host === '' ? '127.0.0.1' : $host, $port];
    }
}
