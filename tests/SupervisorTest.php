<?php

declare(strict_types=1);

/**
 * `Volucer\Supervisor`: the `attach` half of the SDK.
 *
 * Two kinds of test live here, and the split is deliberate.
 *
 * The **pure** ones — `relayPath()`, the argument checks, the refusal on a build without `pcntl` —
 * need no process and run everywhere, including the Windows CI leg where the fork does not exist.
 *
 * The **forking** ones start a real supervisor in a child process, speak the worker protocol to it
 * over a real `AF_UNIX` socket, and are skipped on Windows and anywhere `ext-pcntl` is missing.
 * They are the ones that matter, because every failure mode this class has is a *process* failure:
 * a child that inherits the listener and never closes it, a child that never exits so the slot
 * never comes back, a zombie per connection, a socket file left behind for the next start to trip
 * over. None of those can be observed from inside one process.
 *
 * The client side here writes and reads frames with the SDK's own codec, so the test is not a
 * second implementation of the wire format — only of the *exchange*, which is what a server does.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\Supervisor;

$hasFork = function_exists('pcntl_fork') && PHP_OS_FAMILY !== 'Windows';

// ------------------------------------------------------------------- pure

Assert::same('/run/app.sock', Supervisor::relayPath('unix:/run/app.sock'), 'unix: is stripped to the path');
Assert::same('/run/app.sock', Supervisor::relayPath('  unix:/run/app.sock  '), 'surrounding whitespace is trimmed');
Assert::same(null, Supervisor::relayPath('127.0.0.1:9700'), 'a TCP relay has no path');
Assert::same('', Supervisor::relayPath('unix:'), 'unix: with no path is an empty path, not a TCP one');

// ------------------------------------------- the forking hazard grpc introduces

/*
 * `grpc` starts an executor, a resolver and a timer thread when its module initialises, and a
 * `fork()` after that inherits locks no surviving thread will release: the child deadlocks in
 * `exit()`, stays in state `S`, and never fires `SIGCHLD`. Measured on a machine whose CLI PHP
 * loads grpc — nine stuck children after eight recycles, none with the variable set.
 *
 * The assertion is conditional on grpc actually being loaded, because on a PHP without it the
 * hazard cannot arise and a test that demanded the message would fail for the right reason on the
 * wrong machine. What is asserted unconditionally is the other half: a variable that reports the
 * opt-in silences it.
 */
if (!extension_loaded('grpc')) {
    Assert::same(null, Supervisor::grpcForkHazard(), 'no grpc means no hazard, whatever the environment says');
} else {
    $previousFork = getenv('GRPC_ENABLE_FORK_SUPPORT');
    $setFork = static function (?string $value): void {
        putenv($value === null ? 'GRPC_ENABLE_FORK_SUPPORT' : 'GRPC_ENABLE_FORK_SUPPORT=' . $value);
    };

    try {
        $setFork(null);
        Assert::contains(
            'GRPC_ENABLE_FORK_SUPPORT=1',
            (string) Supervisor::grpcForkHazard(),
            'unset: the hazard is reported, and the message names the variable to set',
        );

        // Only the three spellings grpc itself accepts as "on" silence the report. An empty value
        // and `0` mean "off", which is the state that stalls — treating them as consent was my
        // first version of this assertion, and it would have hidden the very hazard it tests for.
        foreach (['', '0'] as $off) {
            $setFork($off);
            Assert::contains(
                'GRPC_ENABLE_FORK_SUPPORT=1',
                (string) Supervisor::grpcForkHazard(),
                sprintf('"%s" means off, so the hazard must still be reported', $off),
            );
        }

        foreach (['1', 'true', 'yes'] as $on) {
            $setFork($on);
            Assert::same(
                null,
                Supervisor::grpcForkHazard(),
                sprintf('"%s" is grpc\'s opt-in, so there is no hazard left to report', $on),
            );
        }
    } finally {
        $setFork($previousFork === false ? null : $previousFork);
    }
}

// ------------------------------------------- where the relay address comes from

/*
 * The argument wins, the environment is the fallback, and an empty string means "unset" in both
 * places. The last rule is not pedantry: `Environment=VOLUCER_RELAY_SOCKET=` in a unit file and
 * `export VOLUCER_RELAY_SOCKET=` in a shell both produce an empty value, and reporting *that* as
 * an empty address sends an operator looking for a typo in a file that never mentions the key.
 */
$previousRelay = getenv(Supervisor::RELAY_ENV);

$withRelay = static function (string $value): void {
    putenv(Supervisor::RELAY_ENV . '=' . $value);
};
$withoutRelay = static function (): void {
    putenv(Supervisor::RELAY_ENV);
};

$withoutRelay();
Assert::same('/run/from-flag.sock', Supervisor::relay('/run/from-flag.sock'), 'the argument is used when given');
Assert::same(null, Supervisor::relay(null), 'no argument and no environment is null, not an empty string');

$withRelay('unix:/run/from-env.sock');
Assert::same(
    'unix:/run/from-env.sock',
    Supervisor::relay(null),
    'the environment is the fallback when the argument is absent',
);
Assert::same(
    '/run/from-flag.sock',
    Supervisor::relay('/run/from-flag.sock'),
    'and the argument still wins over it, so a stale variable cannot move a deployment',
);
Assert::same('unix:/run/from-env.sock', Supervisor::relay('  '), 'a blank argument is absent, not an address');

$withRelay('   ');
Assert::same(null, Supervisor::relay(null), 'a whitespace-only environment value is unset');

$withRelay('  unix:/run/padded.sock  ');
Assert::same('unix:/run/padded.sock', Supervisor::relay(null), 'the environment value is trimmed like the argument');

if (is_string($previousRelay)) {
    $withRelay($previousRelay);
} else {
    $withoutRelay();
}

/*
 * The refusal on a build without `pcntl` is checked *before* the listener is created, so a
 * supervisor that cannot fork leaves no socket file behind. On a build that can fork the same call
 * would block, so it is only asserted where it is observable.
 */
if (!$hasFork) {
    Assert::throws(
        static fn () => Supervisor::serve('unix:' . sys_get_temp_dir() . '/never.sock', static fn () => null),
        'a build without pcntl refuses rather than serving one process',
        'pcntl',
    );
    Assert::same(
        false,
        file_exists(sys_get_temp_dir() . '/never.sock'),
        'the refusal happens before the listener exists, so it leaves no socket file behind',
    );
}

// ------------------------------------------------------- the real fork path

if (!$hasFork) {
    exit(Assert::report('SupervisorTest (fork tests skipped: no pcntl)'));
}

/** A temporary directory, removed at the end. */
function supervisor_dir(): string
{
    $path = sys_get_temp_dir() . '/volucer-supervisor-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($path, 0700, true);

    return $path;
}

/** Remove a directory tree. */
function supervisor_remove(string $path): void
{
    foreach (glob($path . '/*') ?: [] as $entry) {
        is_dir($entry) ? supervisor_remove($entry) : @unlink($entry);
    }
    @rmdir($path);
}

/**
 * A supervisor driven by the SDK, with a handler that names the child that served.
 *
 * `echo` on purpose: the body proves that the SDK's output capture still owns stdout in a forked
 * child, which is the one thing a fork could plausibly have broken — the capture is a per-process
 * buffer, and the child is a copy of the master rather than a fresh process. The handler returns
 * `null` so the echoed bytes *become* the body; returning a `Response` would take precedence and
 * the test would stop proving the capture works.
 */
const SUPERVISOR_FIXTURE = <<<'PHP'
<?php

require $argv[1];

use Volucer\Request;
use Volucer\Supervisor;

$relay = $argv[2];
$log = $argv[3];
$cap = (int) $argv[4];

// The boot the children inherit. Written to the log so the test can tell the master's one boot
// from the children's zero boots.
file_put_contents($log, "boot " . getmypid() . "\n", FILE_APPEND);

Supervisor::serve(
    $relay,
    static function (Request $request) use ($log) {
        file_put_contents($log, "served " . getmypid() . " " . $request->uri . "\n", FILE_APPEND);
        echo "pid=" . getmypid() . " uri=" . $request->uri;

        return null;
    },
    $cap,
);
PHP;

/** Start a supervisor fixture and wait until its relay is listening. */
function supervisor_start(string $dir, int $cap): array
{
    $script = $dir . '/supervisor.php';
    file_put_contents($script, SUPERVISOR_FIXTURE);

    $log = $dir . '/supervisor.log';
    $relayPath = $dir . '/relay.sock';

    $pipes = [];
    // An *array* command, so `proc_open` execs PHP directly instead of going through a shell. With
    // a string command the pid returned is the shell's, and a `SIGTERM` sent to it would leave the
    // supervisor orphaned and still holding the relay — which is precisely what the teardown
    // assertion below has to be able to observe.
    //
    // stderr goes to a *file* rather than a pipe: the supervisor outlives each test section, so a
    // pipe would have to be drained concurrently to avoid blocking on a full buffer, and the tests
    // that assert on it would have to know when to stop reading. A file has neither problem.
    //
    // `GRPC_ENABLE_FORK_SUPPORT=1` is the hazard `Supervisor::grpcForkHazard()` reports: grpc starts
    // an executor thread at module init, a `fork()` after that inherits its locks, and the child
    // deadlocks in `exit()` — so it stays in `S` and is never reaped. Inheriting the environment is
    // not enough, because the machine this was measured on has the variable unset and grpc loaded,
    // which is exactly the state a deployment would be in.
    $environment = array_merge($_ENV, ['GRPC_ENABLE_FORK_SUPPORT' => '1']);
    $process = proc_open(
        [PHP_BINARY, $script, __DIR__ . '/../autoload.php', 'unix:' . $relayPath, $log, (string) $cap],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $dir . '/supervisor.err', 'w']],
        $pipes,
        null,
        $environment,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('could not start the supervisor fixture');
    }

    stream_set_blocking($pipes[1], false);

    // Readiness comes from the supervisor's own announcement, not from a probe connection. A probe
    // is a connection like any other: it is accepted, forked for, and served — so it leaves a child
    // that fails its handshake in the log, and a test that later asserts on that log would be
    // measuring the probe instead of the thing it meant to measure.
    $errFile = $dir . '/supervisor.err';
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        if (str_contains((string) @file_get_contents($errFile), 'listening on')) {
            return [$process, $pipes, $relayPath, $log];
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            throw new RuntimeException(
                "the supervisor exited during startup:\n" . (string) @file_get_contents($errFile)
            );
        }

        usleep(20_000);
    }

    throw new RuntimeException(
        'the supervisor never announced a listener on ' . $relayPath . "\n"
        . (string) @file_get_contents($errFile)
    );
}

/** Stop a supervisor fixture, returning the exit status. */
function supervisor_stop($process, array $pipes): int
{
    @proc_terminate($process);

    $deadline = microtime(true) + 10;
    $status = ['running' => true, 'exitcode' => -1];
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(20_000);
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }

    @proc_close($process);

    return (int) $status['exitcode'];
}

/**
 * One exchange with a supervised child: HELLO, one REQUEST, and the body that came back.
 *
 * @return array{body: string, keep_alive: bool}
 */
function supervisor_exchange(string $relayPath, string $uri): array
{
    $connection = @stream_socket_client('unix://' . $relayPath, $errno, $errstr, 5);
    if ($connection === false) {
        throw new RuntimeException("could not connect to the relay: {$errstr} ({$errno})");
    }

    stream_set_timeout($connection, 5);
    stream_set_blocking($connection, true);

    fwrite($connection, (new Frame(MessageType::HELLO, 0, json_encode([
        'protocol_version' => Header::PROTOCOL_VERSION,
    ], JSON_THROW_ON_ERROR)))->encode());
    fflush($connection);

    $frames = supervisor_read_until($connection, MessageType::HELLO_ACK, static fn () => true);

    $request = [
        'method' => 'GET',
        'uri' => $uri,
        'protocol' => 'HTTP/1.1',
        'headers' => [['Host', 'example.test']],
        'remote_addr' => '127.0.0.1',
        'script_filename' => 'index.php',
        'body_len' => 0,
        'https' => false,
    ];
    fwrite($connection, (new Frame(MessageType::REQUEST, 1, json_encode($request, JSON_THROW_ON_ERROR)))->encode());
    fflush($connection);

    $ended = null;
    $body = '';
    while (true) {
        [$header, $payload] = supervisor_read_frame($connection);
        if ($header === null) {
            throw new RuntimeException('the worker closed the connection before END');
        }
        if ($header->messageType === MessageType::BODY) {
            $body .= $payload;
        }
        if ($header->messageType === MessageType::END) {
            $ended = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            break;
        }
        if ($header->messageType === MessageType::ERROR) {
            throw new RuntimeException('the worker reported an error: ' . $payload);
        }
    }

    fclose($connection);

    return ['body' => $body, 'keep_alive' => (bool) ($ended['keep_alive'] ?? true)];
}

/**
 * Read frames until one of the wanted type arrives, checking each on the way.
 *
 * @param callable(array{header: Header, payload: string}): bool $accept
 * @return list<array{header: Header, payload: string}>
 */
function supervisor_read_until($connection, int $type, callable $accept): array
{
    $frames = [];
    while (true) {
        [$header, $payload] = supervisor_read_frame($connection);
        if ($header === null) {
            throw new RuntimeException('the stream ended while waiting for a frame');
        }
        $frames[] = ['header' => $header, 'payload' => $payload];
        if ($header->messageType === $type && $accept(end($frames))) {
            return $frames;
        }
    }
}

/**
 * One frame, or `[null, '']` at end of stream.
 *
 * @return array{0: ?Header, 1: string}
 */
function supervisor_read_frame($connection): array
{
    $raw = supervisor_read_exactly($connection, Header::HEADER_LEN);
    if ($raw === '') {
        return [null, ''];
    }

    $header = Header::decode($raw);
    $payload = $header->payloadLen > 0 ? supervisor_read_exactly($connection, $header->payloadLen) : '';

    if (!$header->declaresNoChecksum() && Frame::crc32c($payload) !== $header->checksum) {
        throw new RuntimeException('the worker wrote a frame with a bad checksum');
    }

    return [$header, $payload];
}

/** Read exactly `$length` bytes, or throw. An empty string means clean end of stream. */
function supervisor_read_exactly($connection, int $length): string
{
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = fread($connection, $length - strlen($bytes));
        if ($chunk === false) {
            throw new RuntimeException('the relay became unreadable');
        }
        if ($chunk === '') {
            if (stream_get_meta_data($connection)['timed_out']) {
                throw new RuntimeException('timed out reading from the relay');
            }
            if ($bytes === '') {
                return '';
            }
            throw new RuntimeException('the connection ended in the middle of a frame');
        }
        $bytes .= $chunk;
    }

    return $bytes;
}

/** Every pid the supervisor forked, from the fixture's own log. */
function supervisor_served_pids(string $log): array
{
    $pids = [];
    foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^served (\d+) /', $line, $match) === 1) {
            $pids[] = (int) $match[1];
        }
    }

    return $pids;
}

/**
 * Wait until the supervisor's children satisfy `$done`, or the deadline passes.
 *
 * The predicate rather than a sleep-then-assert, because the master reaps at the top of its accept
 * loop and that loop is inside `accept` for up to `ACCEPT_TIMEOUT` when nothing is arriving. A
 * fixed sleep would either be measuring the timeout or be flaky against it.
 *
 * @param callable(list<int>): bool $done
 * @return list<int>|null the children at the moment the predicate held, or at the deadline;
 *                        `null` when the process table is unreadable
 */
function supervisor_wait_for_children(int $pid, callable $done, float $timeout): ?array
{
    $deadline = microtime(true) + $timeout;

    while (true) {
        $children = supervisor_children($pid);

        if ($children === null || $done($children) || microtime(true) >= $deadline) {
            return $children;
        }

        usleep(20_000);
    }
}

/** The supervisor's own children that are still in the process table (running or zombie). */
function supervisor_children(int $pid): ?array
{
    // `/proc/<pid>/task/<pid>/children` is the direct answer, but a kernel without
    // `CONFIG_PROC_CHILDREN` — WSL1, for one — does not have it, and a supervisor test that
    // silently gives up there would not be testing the reaper on the platform this is developed
    // against. The `PPid:` fallback walks `/proc` instead, which every Linux has.
    $path = "/proc/{$pid}/task/{$pid}/children";

    if (is_readable($path)) {
        $list = trim((string) @file_get_contents($path));

        return $list === '' ? [] : array_map('intval', preg_split('/\s+/', $list) ?: []);
    }

    if (!is_dir('/proc')) {
        return null;
    }

    $children = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $status = @file_get_contents("/proc/{$entry}/status");
        if (is_string($status) && preg_match('/^PPid:\s+(\d+)/m', $status, $match) === 1
            && (int) $match[1] === $pid
        ) {
            $children[] = (int) $entry;
        }
    }

    return $children;
}

// ------------------------------------------------------------- the exchange

$dir = supervisor_dir();

try {
    [$process, $pipes, $relayPath, $log] = supervisor_start($dir, 0);
    $master = proc_get_status($process)['pid'];

    $first = supervisor_exchange($relayPath, '/first');
    Assert::contains('uri=/first', $first['body'], 'a request reaches the supervised child');
    Assert::contains('pid=', $first['body'], 'the body is the handler output, so capture still owns stdout in a child');
    Assert::true($first['keep_alive'], 'the child answers keep_alive, so volucer keeps the slot');

    // The second connection must be a *different* child: each serves one connection and exits, and
    // the master forks a replacement. A supervisor that reused the first child would show the same
    // pid here, and would also never notice a recycled slot.
    $second = supervisor_exchange($relayPath, '/second');
    Assert::contains('uri=/second', $second['body'], 'a second connection is served');

    preg_match('/pid=(\d+)/', $first['body'], $a);
    preg_match('/pid=(\d+)/', $second['body'], $b);
    Assert::true((int) $a[1] !== (int) $b[1], 'each connection is served by a different forked child');

    // The boot is the master's, and only the master's: a child that booted for itself would show a
    // second `boot` line, which is exactly the cost the fork is supposed to remove.
    $boots = array_values(array_filter(
        file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        static fn (string $line): bool => str_starts_with($line, 'boot '),
    ));
    Assert::same(1, count($boots), 'the application is booted once, in the master, not once per child');
    Assert::contains((string) $master, $boots[0], 'the one boot is the supervisor process itself');

    Assert::same(
        count(supervisor_served_pids($log)),
        count([$first, $second]),
        'each exchange is served exactly once, so no request was answered twice',
    );

    // **Zombies.** A child that exits stays a zombie until its parent waits for it, so a supervisor
    // that only accepts and forks grows one per connection — measured at exactly 10 for 10
    // connections before the reaper existed. Both children have exited by now, so a working reaper
    // leaves the process table empty.
    //
    // The wait is for the master's own accept timeout rather than for the children: the reap
    // happens at the top of the loop, and the loop is inside `accept` for up to half a second at a
    // time when no traffic is arriving. Asserting sooner would be measuring the timeout rather than
    // the reaper.
    $children = supervisor_wait_for_children($master, static fn (array $list): bool => $list === [], 3.0);
    if ($children === null) {
        fwrite(STDOUT, "note: /proc is unreadable, skipping the zombie assertion\n");
    } else {
        Assert::same(
            [],
            $children,
            'the supervisor reaps its children (left in the process table: ' . implode(',', $children) . ')',
        );
    }

    // A stale socket file is a file, so a restarted supervisor must remove it before binding. The
    // teardown does it on the way out too, which is what makes a `systemctl restart` work.
    supervisor_stop($process, $pipes);
    Assert::same(false, file_exists($relayPath), 'stopping the supervisor removes its socket file');
} finally {
    supervisor_remove($dir);
}

// ------------------------------------------------------------------ the cap

/*
 * `children` is a cap, and the default is deliberately *no* cap: volucer dials `workers` times and
 * holds each connection as a slot, so a cap below that number is a startup failure rather than a
 * queue. What the cap must still do is refuse to fork a third child while two are serving, and the
 * observable consequence is that a third connection waits in the backlog instead of being served.
 */
$dir = supervisor_dir();

try {
    [$process, $pipes, $relayPath, $log] = supervisor_start($dir, 1);

    $first = supervisor_exchange($relayPath, '/capped');
    Assert::contains('uri=/capped', $first['body'], 'a capped supervisor still serves');

    supervisor_stop($process, $pipes);
    Assert::same(1, count(supervisor_served_pids($log)), 'a cap of 1 served the one request with one child');
} finally {
    supervisor_remove($dir);
}

// ------------------------------------------------------------ the drain race

/*
 * A peer that sends `CONTROL drain` and closes *without reading the ack* is the `attach` recycle
 * path, and it is what `Slot::stop()` in the server does on every recycled slot. Two things have to
 * hold, and both did not before this was tolerated:
 *
 *  - the child exits cleanly, so the supervisor does not log a failed worker on every recycle;
 *  - the supervisor stays healthy and serves the next connection, which is the whole recycling
 *    contract.
 *
 * The peer here is the test itself rather than a volucer binary, so this is the one assertion in the
 * suite that does not depend on the server being built.
 */
$dir = supervisor_dir();

try {
    [$process, $pipes, $relayPath, $log] = supervisor_start($dir, 0);

    // The readiness announcement above is on stderr, and the drain's own assertion needs a clean
    // slate: truncate so what is read below came from after startup.
    file_put_contents($dir . '/supervisor.err', '');

    $connection = @stream_socket_client('unix://' . $relayPath, $errno, $errstr, 5);
    if ($connection === false) {
        throw new RuntimeException("could not connect to the relay: {$errstr} ({$errno})");
    }

    fwrite($connection, (new Frame(MessageType::HELLO, 0, json_encode([
        'protocol_version' => Header::PROTOCOL_VERSION,
    ], JSON_THROW_ON_ERROR)))->encode());
    fflush($connection);
    supervisor_read_until($connection, MessageType::HELLO_ACK, static fn () => true);

    // The control frame, then the immediate close — no read of `CONTROL_ACK` in between.
    fwrite($connection, (new Frame(MessageType::CONTROL, 0, json_encode([
        'kind' => 'drain',
    ], JSON_THROW_ON_ERROR)))->encode());
    fflush($connection);
    fclose($connection);

    // The next connection must be answered: a supervisor whose child died on the broken pipe would
    // still pass the assertion above, and would fail this one.
    $after = supervisor_exchange($relayPath, '/after-the-drain');
    Assert::contains('uri=/after-the-drain', $after['body'], 'the supervisor keeps serving after a drain race');

    // And the child must not have reported a failure. `Supervisor::serveOne` writes exactly one line
    // per child that raised, so the assertion is that stderr is empty — anything here is a child
    // that took the broken pipe as its own error.
    $stderr = (string) @file_get_contents($dir . '/supervisor.err');
    Assert::same(
        '',
        trim($stderr),
        'a drained child exits cleanly even though its ack could not be written',
    );

    supervisor_stop($process, $pipes);
} finally {
    supervisor_remove($dir);
}

exit(Assert::report('SupervisorTest'));
