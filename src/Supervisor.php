<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A prefork supervisor for volucer's `attach` backend.
 *
 * The other half of `attach`. volucer only *dials* a relay in this mode — it starts no process
 * at all — because the fork has to happen in the application's own process: a PHP process cannot
 * hand a file descriptor to another process (`ext/sockets` has no `SCM_RIGHTS`), so the side that
 * owns the listening socket has to be the side that owns the fork. This class is that side.
 *
 * ```php
 * require __DIR__ . '/vendor/autoload.php';
 *
 * $app = new App();
 * $app->bootstrap();                 // boot before the fork, or each child boots its own
 *
 * Volucer\Supervisor::serve(
 *     'unix:/run/app/workers.sock',
 *     static fn (Volucer\Request $request): Volucer\Response => $app->handle($request),
 * );
 * ```
 *
 * ```toml
 * [backends.app]
 * kind    = "attach"
 * relay   = "unix:/run/app/workers.sock"
 * workers = 16
 * ```
 *
 * The relay may also be `host:port`, which is what a supervisor in another container needs;
 * `unix:` matters because a loopback TCP relay pays Nagle/delayed-ACK unless both ends disable it,
 * and an `AF_UNIX` socket has no Nagle to disable. [`Supervisor::listen`] sets `tcp_nodelay` on a
 * TCP listener, which every accepted connection inherits, so a child is never left to set it.
 *
 * **Unix only.** Windows has no `pcntl`, and `serve()` refuses there rather than silently degrading
 * to one process. On Windows use `kind = "worker"` (volucer spawns the processes) or
 * `kind = "upstream"`.
 *
 * **Boot before the fork.** Whatever the handler closes over is inherited by every child — the
 * container, loaded classes, the OPcache segment — so build it before calling `serve()`. What must
 * *not* be built before the fork is an open connection: every child would inherit it and they would
 * take turns reading from it. Open those per child, lazily, on the first request.
 *
 * **A PHP that loads `grpc` must be started with `GRPC_ENABLE_FORK_SUPPORT=1`.**
 *
 * `grpc` starts an executor, a resolver and a timer thread when its module initialises. A `fork()`
 * after that inherits those threads' locks, and the child deadlocks the first time it calls
 * `exit()` — inside grpc's own module shutdown. The symptom is not a crash: the child stays in
 * state `S` on a futex for ever, so `SIGCHLD` never fires, the master never reaps it, and a
 * supervisor that caps `children` stops accepting. Measured on a machine whose CLI PHP loads grpc:
 * nine stuck children after eight recycles, and none with the variable set.
 *
 * It has to be an environment variable, not `-d grpc.enable_fork_support=1` and not `putenv()` from
 * PHP: grpc starts those threads in its module init, which runs before any script does, so a value
 * set from PHP changes what `--ri grpc` reports and nothing else. Measured — `putenv()` at the top
 * of the script still leaves the child stuck (`exit()` never returns), while the same variable in
 * the environment lets it exit and be reaped.
 *
 * ```ini
 * # worker.service
 * Environment=GRPC_ENABLE_FORK_SUPPORT=1
 * ```
 *
 * ```bash
 * GRPC_ENABLE_FORK_SUPPORT=1 php supervisor.php    # or in the container's environment
 * ```
 *
 * `Supervisor::serve()` cannot do this for you, because the threads already exist by the time any
 * PHP code runs. It reports the hazard in {@see Supervisor::grpcForkHazard()} so a caller can log
 * it rather than discover it as a stalled pool.
 */
final class Supervisor
{
    /** The `unix:` prefix volucer's configuration uses for a path relay. */
    public const UNIX_PREFIX = 'unix:';

    /**
     * The environment variable naming the relay an `attach` backend dials.
     *
     * The counterpart of [`Worker::SOCKET_ENV`], and for the same purpose: one address both halves
     * of a deployment have to agree on, written once. The difference is direction. That variable is
     * set *by* the server, on a child it spawned, so a supervisor cannot use it — there is no
     * spawned child, and the value there would be whatever an enclosing environment happened to
     * leave. This one is set by the *operator*, in the unit file or the container environment, and
     * both the server and the supervisor read it.
     */
    public const RELAY_ENV = 'VOLUCER_RELAY_SOCKET';

    /**
     * The relay to listen on: the argument, else the environment, else `null`.
     *
     * `null` rather than an exception, because whether a missing relay is an error depends on the
     * caller. [`Supervisor::serve`] takes `null` to mean the caller should fail with a message
     * naming both places; a test or an embedding application may want to decide for itself.
     *
     * The trimming rule is [`Worker::socketAddress`]'s, deliberately: an empty value is what a
     * shell produces from `VOLUCER_RELAY_SOCKET=`, and reporting it as an empty address would
     * send the operator looking for a typo in a file that never mentions the key.
     */
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

    /**
     * How long `accept` waits before the master reaps children and looks again.
     *
     * It costs a caller nothing: `accept` returns the instant a connection is pending, so this only
     * bounds how long the master sleeps when no traffic is arriving — which is exactly when it
     * needs to be reaping. The same figure as the Python and Ruby supervisors.
     */
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
     * Bind and listen on a relay, removing a stale socket file first.
     *
     * A unix socket path is a *file*, and binding one that already exists fails with `EADDRINUSE` —
     * so a supervisor restarted after a crash could never come up. Unlinking first is what every
     * Unix server does, and it is safe here because this process is meant to be the only listener
     * on that path: a live supervisor would hold the socket, and the bind would fail rather than
     * two processes sharing one relay.
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
     * Listen on `$relay` and fork one child per accepted connection.
     *
     * `$handler` is the same callable [`Worker::run`] takes, so nothing has to be adapted: the
     * handler a `worker` backend runs unchanged is the handler this serves.
     *
     * `$children` caps how many children may run at once, not a queue depth. `0` — the default —
     * means **no cap**, which is what an `attach` deployment wants: volucer dials the relay exactly
     * `workers` times at startup and holds one connection per slot, so the live children are
     * already bounded by that number. A cap is only useful to reserve memory for *other* processes
     * on the host, and a cap **below** `workers` is not a queue — it is a startup failure, because
     * the surplus dials sit unaccepted in the listen backlog while the pool waits for a handshake
     * no child is there to send.
     *
     * Each child serves exactly one connection and exits, which is what makes recycling cheap and
     * the assignment exact: volucer closes a slot, the child sees end-of-file and exits, and this
     * loop forks a replacement for the next connection.
     *
     * The master runs until it is asked to stop. `SIGTERM`/`SIGINT` stop it accepting, close the
     * listener, remove the socket file, and wait briefly for the children already serving — the
     * graceful half of what a service manager expects.
     *
     * `$relay` may be `null`, in which case [`Supervisor::relay`] reads `VOLUCER_RELAY_SOCKET`. That
     * is the form a deployment should use: the server reads the same variable for its `relay`, so
     * the address is written once instead of in two files that nothing keeps in sync. A value
     * passed here still wins, so a script that names its own socket keeps meaning what it says.
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

        // Warned rather than refused: the hazard shows up as a stalled pool under load, and
        // taking a serving deployment down for it would be the worse answer. See the method.
        $hazard = self::grpcForkHazard();
        if ($hazard !== null) {
            @fwrite(STDERR, '[volucer] warning: ' . $hazard . "\n");
        }

        $limit = $children > 0 ? $children : null;
        $listener = self::listen($relay);

        // The SDK is loaded *before* the first fork, and that is load-bearing rather than tidy.
        // The autoloader is lazy, so a class the master has not touched is compiled by the first
        // child that needs it — and a `require` that fatals in a child after the master has already
        // said "listening" shows up as a connection reset on the pool's first request rather than
        // as the error it is. It is also half the memory argument: a class table the master has
        // loaded is inherited.
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
                    // Every slot is busy. Waiting rather than accepting keeps the assignment exact:
                    // a connection taken now would sit in a child that has no slot to serve.
                    usleep(self::SLOT_BACKOFF_US);

                    continue;
                }

                $connection = @stream_socket_accept($listener, self::ACCEPT_TIMEOUT);
                if ($connection === false) {
                    // A timeout, or a signal that interrupted the accept — expected once per
                    // exiting child. Either way the loop goes back to waiting, and the reap at the
                    // top of the next iteration collects the child that caused it.
                    continue;
                }

                $pid = pcntl_fork();

                if ($pid === -1) {
                    // Out of processes. Dropping the connection is the honest answer: volucer sees
                    // a closed socket on that slot and replaces it on the next request, where
                    // pretending to have forked would leave a slot waiting for an answer that never
                    // comes.
                    fclose($connection);

                    continue;
                }

                if ($pid !== 0) {
                    // The master must drop its copy, or the socket never reaches end-of-file when
                    // the child exits and one fd leaks per connection.
                    fclose($connection);
                    $live[$pid] = true;

                    continue;
                }

                // --- the child ---

                fclose($listener);

                // The master's signal handling is meaningless here: a handler inherited by a child
                // would swallow the `SIGTERM` a service manager sends to the whole group and leave
                // this process serving a connection nobody is waiting for.
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
     * Serve one connection in a forked child, then exit.
     *
     * The connection is already open, so this is [`Worker::create`] and not [`Worker::connect`] —
     * the whole of what `attach` asks of the SDK.
     *
     * `exit` rather than a normal return, and that is deliberate twice over: normal shutdown would
     * run the master's shutdown functions once per child, and it would flush any output buffer the
     * application left open, so every child would write the master's bytes again — the classic
     * duplicated-output bug of a forked server. The buffers are discarded here for that reason,
     * because PHP's `exit` flushes them.
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

            // stderr, never stdout: the child inherited the supervisor's, which is where a service
            // manager collects it. `Worker` already sends PHP's own warnings there.
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
     * Reap every child that has exited, dropping it from `$live`.
     *
     * A child that exits stays a zombie until its parent waits for it, so a supervisor that only
     * accepts and forks grows one per connection — measured at exactly 10 zombies for 10
     * connections. `WNOHANG` in a loop is what keeps this from blocking in the middle of the accept
     * loop. Deliberately not a `SIGCHLD` handler, which the reference supervisor in
     * `volucer-competition` uses: a handler reaps behind this loop's back, so the live count would
     * never fall and a capped supervisor would stop accepting after `children` connections. One
     * reaper, one place.
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
     * Give the children already serving a bounded chance to finish.
     *
     * Bounded because a child may be mid-request and the service manager will escalate anyway; an
     * unbounded wait would turn a shutdown into a hang.
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

    /**
     * Install the shutdown handler.
     *
     * Only a flag is set: the accept loop owns the teardown, because closing the listener from a
     * signal handler that may have interrupted `accept` on that same listener is a race worth not
     * having.
     */
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

    /**
     * The one readiness line a deployment needs.
     *
     * A unit file or a container healthcheck can wait for this line rather than guessing, and it is
     * on stderr because stdout belongs to whatever the application might print.
     */
    private static function announce(string $relay, ?int $children): void
    {
        @fwrite(STDERR, sprintf(
            "[volucer] listening on %s (%s)\n",
            $relay,
            $children === null ? 'no child cap' : $children . ' children at most',
        ));
    }

    /**
     * Refuse on a build that cannot fork, naming the platform's own alternative.
     *
     * Checked before the listener is created, so a refusal leaves no socket file behind for the
     * next attempt to trip over.
     */
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
     * The reason a forking PHP will stall, if one applies here, or `null`.
     *
     * `grpc` starts an executor, a resolver and a timer thread when its module initialises, and a
     * `fork()` after that inherits locks no surviving thread will release. The child deadlocks in
     * `exit()` — inside grpc's module shutdown — and stays in state `S`, so `SIGCHLD` never fires
     * and the master never reaps it. Measured: nine stuck children after eight recycles with the
     * hazard present, none with `GRPC_ENABLE_FORK_SUPPORT=1` in the environment.
     *
     * `serve()` calls this and warns on stderr rather than throwing, because the failure is a
     * stall under load rather than a broken start-up: refusing to start would take a working
     * deployment down on a warning. It is public so a caller can turn it into a hard failure
     * where a stalled pool is worse than no server.
     */
    public static function grpcForkHazard(): ?string
    {
        if (!\extension_loaded('grpc')) {
            return null;
        }

        // grpc's own opt-in for a process that forks. It has to be an environment variable: the
        // `grpc.enable_fork_support` ini setting is read after the threads already exist, so an
        // `-d` flag reports the requested value and changes nothing.
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
