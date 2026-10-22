<?php

declare(strict_types=1);

/**
 * The same routes as `worker.php`, served through the `attach` backend.
 *
 *     php example/supervisor.php                 # the relay from VOLUCER_RELAY_SOCKET
 *     php example/supervisor.php unix:/run/app/workers.sock
 *
 * The argument is optional on purpose. The address is the one fact the server's `relay` and this
 * process both have to know, so the deployment can write it once — in the unit file or the
 * container's environment — and both sides read it. A value given here still wins, which is what
 * makes a hand-run supervisor possible without touching the environment.
 *
 * This is the whole difference between the two files, and it is worth seeing side by side:
 * `worker.php` calls `Worker::connect()` and serves *one* connection that the server opened;
 * this calls `Volucer\Supervisor::serve()` and serves *many*, forking a child per connection
 * from the heap it inherited. The routes and the handler are identical.
 *
 * ```toml
 * [backends.app]
 * kind    = "attach"
 * relay   = "unix:/run/app/workers.sock"
 * workers = 4
 * ```
 *
 * **Unix only.** `Supervisor::serve()` needs `ext-pcntl`, which does not exist on Windows, and
 * refuses there rather than quietly serving one process. See the README's
 * "Attaching instead of spawning".
 */

require __DIR__ . '/../autoload.php';

use Volucer\Request;
use Volucer\Response;
use Volucer\Supervisor;
use Volucer\Worker;

$relay = Supervisor::relay($argv[1] ?? '');

if ($relay === null) {
    fwrite(
        STDERR,
        "usage: php example/supervisor.php [unix:/path/to.sock|host:port]\n"
            . 'or set ' . Supervisor::RELAY_ENV . " to the same relay the server's `relay` names\n",
    );
    exit(2);
}

/*
 * The boot every child inherits.
 *
 * Nothing here is expensive enough to be worth measuring, but the *position* is the point: an
 * application that builds a container, compiles templates or warms a router does it once, here,
 * before the first fork. Everything a child then does on its first request is inherited work it
 * does not pay for again.
 *
 * What must not be here is an open connection — a database handle, a Redis client. Every child
 * would inherit the socket and they would take turns reading from it. Open those lazily, in the
 * handler, so each child has its own.
 */
$startedAt = microtime(true);

/**
 * The handler, and the only part of this file that is application code.
 *
 * It returns `null` on some routes and `echo`es instead, which works for the same reason it does
 * under `worker.php`: the SDK captures the application's output and uses it as the body. That
 * capture is per-process, so a forked child has its own — the master's buffer is a copy, not a
 * shared one.
 */
$handler = static function (Request $request, Worker $worker) use ($startedAt): Response|null {
    $path = (string) parse_url($request->uri, PHP_URL_PATH);

    // The pid is the interesting field here: ask twice and you should see two of them, because
    // each child serves one connection and exits.
    if ($path === '/health') {
        return Response::json([
            'status'             => 'ok',
            'pid'                => getmypid(),
            'booted_seconds_ago' => round(microtime(true) - $startedAt, 3),
        ]);
    }

    $worker->emitMetric('example_attach_request_total', 1.0, 'count');
    $worker->emitLog("serving {$request->method} {$request->uri} in pid " . getmypid());

    if ($path === '/boom') {
        throw new RuntimeException('this route always fails, and the child still exits cleanly');
    }

    echo 'served ', htmlspecialchars($request->uri, ENT_QUOTES), "\n";
    echo 'pid ', getmypid(), "\n";
    echo 'booted ', round(microtime(true) - $startedAt, 3), " s ago\n";

    return null;
};

// `relay()` has already resolved the argument against `VOLUCER_RELAY_SOCKET`, so this is an address.
Supervisor::serve($relay, $handler);
