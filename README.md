# volucer-worker — the PHP SDK

The PHP end of the [volucer worker
protocol](https://github.com/sailantis/volucer/blob/2bc32cd50bdad61dba68b0c526802171a47ec488/docs/worker-protocol.md):
a persistent PHP process that boots your application once and serves many requests over
stdin/stdout.

The server itself — the process that spawns and supervises workers — is
[`sailantis/volucer`](https://github.com/sailantis/volucer). This package is only the PHP
half; it is useful on its own but does nothing without a server to speak to.

The protocol link above is pinned to a commit rather than to a branch: a branch link would
silently start describing a later protocol than the one this release implements.

It is **stdlib-only**. No extensions, no FFI, no build step, and no Composer requirement just to
*run* a worker. The whole SDK is four small classes and a codec.

## What it is not

It is a **transport, not a framework**. There is no router, no container, no middleware pipeline,
and no PSR-7 dependency — because forcing one would mean this package could not be used from the
many applications that use a different one. `Request` and `Response` are plain value objects;
adapting them to whatever your application already speaks is a few lines.

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';   // or: require 'path/to/volucer-worker/autoload.php';

use Volucer\Request;
use Volucer\Response;
use Volucer\Worker;

Worker::connect()->run(function (Request $request): Response {
    return Response::text('hello ' . $request->uri);
});
```

Start it with `php worker.php`. It will sit waiting, because a worker speaks the protocol over
stdin/stdout rather than HTTP — point volucer at it, or see
[Example](#example) for how to poke it by hand.

`Worker::connect()` is `Worker::create()` with one extra step: when the server has set
`VOLUCER_WORKER_SOCKET` — which it does on the `socket` transport, **the default on Windows** —
the protocol runs over that socket instead of stdio. Use `connect()` in a worker that a
`volucer` backend starts, and `create()` when you are binding streams yourself.

The variable carries either form, and `connect()` decides from its prefix:

| value | transport |
| --- | --- |
| `127.0.0.1:34567` | loopback TCP — the ephemeral port the server bound |
| `unix:/run/volucer/worker.0.sock` | a unix socket, from `socket = "unix:/run/volucer/worker"` |

`unix:` is the same convention the server's configuration uses for `external`, `attach` and the
worker `socket` key, so there is one spelling rather than a fourth. Nothing else in a worker
changes: `Worker::unixPath()` is the whole of the decision, and it returns `null` for a TCP
address so the connection code has one branch.

This is not cosmetic on Windows. A worker that calls `create()` cannot read a socket, so under
the default transport it reads `php://stdin` — which the server has set to end-of-file — and exits
during its handshake. The server reports that at startup, naming `connect()` as the fix, so it
fails fast and loudly rather than hanging; but `connect()` is the call to use. It is backward
compatible either way: with no address in the environment it is exactly `create()`.

### The socket transport disables Nagle, and that is not a detail

`connect()` opens the socket with `tcp_nodelay` set. Without it every request costs about
**39 ms** instead of 0.06 ms, which is enough to make the transport look broken rather than
merely slower.

The reason is the protocol's shape. A frame is written as a header write followed by a payload
write, and the exchange is a strict ping-pong: request, answer, request. Nagle holds the second
small write until the first is acknowledged, TCP delays the acknowledgement by up to 40 ms, and
the two processes do it to each other once per request. Measured on loopback with a
protocol-shaped ping-pong:

| | per request |
|---|---|
| TCP, Nagle on | **38.96 ms** |
| TCP, `TCP_NODELAY` | **0.06 ms** |
| AF_UNIX socket | 0.06 ms |

On the whole `/items` route this was the difference between a 2,334 rps arm and a 24 rps one. The
server has always disabled Nagle on its side; this closes the same gap on the worker's.

## The two rules

Both are enforced by the SDK, not merely documented. They are the two things that go wrong in
every persistent-PHP protocol, so it is worth knowing they are handled for you.

### 1. stdout is the protocol channel

Every frame goes to `php://stdout`. Anything the *application* writes there would land inside a
frame and desynchronise the stream permanently.

So application output is not banned — it is **captured**:

- `ob_start()` runs around each request, so `echo` and template output are collected rather than
  written.
- `ini_set('display_errors', 'stderr')` sends warnings and notices to stderr, which is not part
  of the protocol.

The captured output becomes the response body when your handler returns `null`. That means **a
plain PHP script works as a worker without being changed** — if it echoes, that echo is the
response. If your handler returns a `Response`, the captured output is discarded.

To talk to the server *outside* a request, use `emitLog()` / `emitMetric()`. Those are the reason
this protocol exists instead of using FastCGI: a worker can speak first.

The residual risk is a write made directly to the `STDOUT` constant
(`fwrite(STDOUT, …)`, `file_put_contents('php://stdout', …)`), which no output buffer can
intercept. Nothing in PHP or any framework produces output that way, and if it happens the
consequence is a checksum mismatch the server reports clearly rather than silent corruption.

### 2. Shutdown by construction

When the server wants the worker to stop it closes the child's stdin. The loop reads that as a
clean EOF and returns from `run()`, so the worker exits on its own. There is **no signal to
catch** and no flag to poll — which is why the server never has to ask twice before killing.

## Attaching instead of spawning

The `worker` backend starts your process. The `attach` backend does not: it *dials* a relay that a
supervisor in your own process listens on, and holds one connection per slot. That is the shape that
lets a supervisor fork its workers after booting the application once, so every child inherits the
interpreter, the loaded classes and the OPcache segment instead of mapping them again.

The split matters: **volucer cannot own the fork.** It has to happen in the application's process,
because a PHP process cannot hand a file descriptor to another one — `ext/sockets` has no
`SCM_RIGHTS`, so the side that owns the listening socket has to be the side that owns the fork. So
the fork lives in `Volucer\Supervisor`, and you call it:

```php
<?php
// supervisor.php — boot once, then fork
require __DIR__ . '/vendor/autoload.php';

$app = new App();
$app->bootstrap();                  // boot before the fork, or every child boots its own

Volucer\Supervisor::serve(
    null,                           // or 'unix:/run/app/workers.sock'
    static fn (Volucer\Request $request): Volucer\Response => new Volucer\Response(
        200,
        [['Content-Type', 'text/html; charset=utf-8']],
        $app->handle($request),
    ),
);
```

```toml
[backends.app]
kind    = "attach"
workers = 16
```

The handler is the same callable `Worker::run()` takes, so a worker file usually becomes a
supervisor by replacing its last line and nothing else.

**The address can be written once.** With the first argument `null` the supervisor reads
`VOLUCER_RELAY_SOCKET`, and so does the server when `relay` is omitted:

```ini
# the unit file, or a container's environment
Environment=VOLUCER_RELAY_SOCKET=unix:/run/app/workers.sock
```

That is the counterpart of `VOLUCER_WORKER_SOCKET`, and it exists for the mirror-image reason. That
one is set *by* the server on a child it spawned, so a supervisor cannot read it: no child was
spawned, and the value there would be whatever an enclosing environment left behind. This one is
set by the operator, and both sides read it. A value passed to `serve()`, or a `relay` key in the
file, still wins — so a stale variable in a shell cannot move a deployment, and a hand-run
supervisor that names its own socket still means it. `Supervisor::relay($relay = null)` is that
resolution on its own, for a caller that wants to decide what "missing" means.

Each child serves exactly one connection and exits. That is what makes recycling cheap — when
`max_requests` is reached volucer closes the slot, the child sees end-of-file, exits, and the
supervisor forks a replacement for the next connection. The supervisor reaps its children, so a
long-lived one does not fill the process table with zombies.

**`children` needs no tuning.** The third argument caps how many children may run at once, and the
default (`0`) is *no cap*, which is what an `attach` deployment wants: volucer dials `workers` times
and holds one connection per slot, so the live children are already bounded by that number. A cap
below `workers` is not a queue — the surplus dials sit unaccepted in the listen backlog while the
pool waits for a handshake no child is there to send.

**Unix only.** Windows has no `pcntl`, and `serve()` raises there rather than silently running a
single process. Use `kind = "worker"` or `kind = "upstream"` on Windows.

**Boot before the fork, connect after it.** A container, loaded classes and a warm template cache
are inherited by every child, which is the saving. An *open database connection* must not be: every
child would inherit the socket and they would take turns reading from it. Open connections lazily,
per child, on the first request — doctrine's `EntityManager` and PDO both do this already.

### Writing a supervisor yourself

`Supervisor::serve()` is a convenience, not the contract. What `attach` needs from any supervisor is
that it serves a connection **it** opened, which is `Worker::create()`:

```php
$listener = stream_socket_server('unix:///run/app/workers.sock', $errno, $errstr);

while (true) {
    $connection = stream_socket_accept($listener, -1);
    $pid = pcntl_fork();

    if ($pid !== 0) {
        fclose($connection);        // the master must drop its copy
        continue;
    }

    fclose($listener);
    Worker::create($connection, $connection)->run($handler);
    exit(0);                        // not a return: a child must not run the master's shutdown
}
```

Two details are load-bearing and easy to miss. `Worker::create()`, not `connect()` — the connection
already exists and is handed over. And the accept has to happen in the master, before the fork: the
kernel wakes one waiter per connection and does it unfairly, so N children sharing one listener can
leave one holding two connections and another none, which for volucer is a slot whose connection
nobody is reading.

## Writing a handler

The handler receives the `Request` and returns one of:

| Return | Result |
| --- | --- |
| `Response` | sent exactly as given |
| `string` | sent as a `200` `text/html` body |
| `null` | the captured output becomes the body |

If the handler throws, the SDK logs it to stderr, answers `500`, and **keeps serving**. One bad
request does not take the worker down.

```php
Worker::create()->run(function (Request $request, Worker $worker): ?Response {
    // Telemetry never touches the response.
    $worker->emitMetric('request_count', 1.0, 'count');
    $worker->emitLog("serving {$request->uri}");

    if ($request->uri === '/health') {
        return Response::json(['status' => 'ok']);
    }

    // Echo case: the captured output is the body, so a plain script works.
    echo 'you asked for ', htmlspecialchars($request->uri, ENT_QUOTES);

    return null;
});
```

### `Request`

| Member | Notes |
| --- | --- |
| `method`, `uri`, `protocol` | as sent |
| `headers` | `name => value`; `header($name)` looks up case-insensitively |
| `body` | the raw request body |
| `contentType()` | shorthand for the `Content-Type` header |
| `query()` | the query string parsed the way PHP populates `$_GET` |
| `cookies()` | the `Cookie` header parsed the way PHP populates `$_COOKIE` |
| `serverVars()` | CGI-style variables derived from the request (for legacy code) |
| `remoteAddr`, `scriptFilename`, `https` | as sent |

### `Response`

```php
Response::text('plain');                       // 200, text/plain
Response::html('<h1>hi</h1>');                 // 200, text/html
Response::json(['ok' => true]);                // 200, application/json
new Response(404, [['Content-Type', 'text/plain']], 'not found');

$response->withStatus(201)->withHeader('X-Trace', 'abc')->withBody('…');
```

`withHeader()` **appends** rather than replaces, so `Set-Cookie` can be sent more than once.

### `Worker`

| Method | Purpose |
| --- | --- |
| `Worker::create($in?, $out?)` | bind explicit streams (default: stdio), force `display_errors=stderr` |
| `Worker::connect()` | `create()`, but honour `VOLUCER_WORKER_SOCKET` when the server sets it (either `host:port` or `unix:/path`) |
| `run($handler)` | handshake, then serve until EOF |
| `emitLog($message, $level)` | one log record, unsolicited |
| `emitMetric($name, $value, $unit)` | one metric sample, unsolicited. `$name` must be a Prometheus name (`[a-zA-Z_][a-zA-Z0-9_]*`) and declared in the server's `[metrics.collect]` |
| `recycle()` | ask the server to replace this worker after the request |
| `currentRequestId()` | the in-flight request id, for telemetry correlation |

### `Supervisor`

The `attach` half, in its own class because it is a different call: `Worker` serves one connection
the server opened, and `Supervisor` serves many, forking one child each. See
[Attaching instead of spawning](#attaching-instead-of-spawning).

| Method | Purpose |
| --- | --- |
| `Supervisor::serve($relay, $handler, $children = 0)` | listen on `unix:/path` or `host:port`, fork per connection, block |
| `Supervisor::listen($relay)` | bind only, for an application that owns its own accept loop |
| `Supervisor::relayPath($relay)` | the path of a `unix:` relay, or `null` for a TCP one |
| `Supervisor::UNIX_PREFIX` | `'unix:'` — volucer's convention, not PHP's |

`recycle()` is what an application calls when it has put itself into a state it cannot clean up —
a leaked global, a stuck connection, a fat static cache. The server sees `keep_alive = false` in
`END` and boots a replacement; the request that asked is still answered normally, so a handler
never has to choose between responding and being cleaned up.

### `Session`

A worker is not a SAPI, so the session half of what PHP normally does for an application is
missing: nothing reads the `Cookie` header, and `header()`/`setcookie()` cannot deliver the
`Set-Cookie` back. `Volucer\Session` does both, and every framework bridge should use it rather
than reimplement it.

| Method | Purpose |
| --- | --- |
| `begin($request)` | adopt the session the request presented, and relax PHP's cookie handling |
| `beginWithCookies($cookies)` | the same, for a request that is not a `Volucer\Request` (PSR-7, a framework's own) |
| `applyTo($response)` | **add the `Set-Cookie` and end the request** — the normal call |
| `cookie()` | the `Set-Cookie` value alone, for a response object this SDK does not own |
| `end()` | end the session and forget the id, if you did not use `applyTo()` |

```php
$session = new Session();

$handler = static function (Request $request, Worker $worker) use ($session): Response {
    $session->begin($request);

    session_start();              // the application's own choice, as under FPM
    $_SESSION['seen'] = true;

    return $session->applyTo(Response::text('ok'));
};
```

**`applyTo()` ends the request as well, deliberately.** `session_id()` outlives the request, so a
client arriving with no cookie would otherwise inherit the previous client's session — and reading
the id *after* ending the session yields `''`, which means a caller that gets the order wrong drops
the cookie **silently**: the session is created on every request, and nothing logs a word. Fusing
the two removes the opportunity. Calling `end()` as well is harmless.

A session is not started for you. A request that never calls `session_start()` — a 404, a static
file — creates no session and gets no cookie, exactly as under FPM.

Each `END` frame reports `peak_memory_bytes` for **that request**, not for the process. The worker
reads `memory_get_peak_usage(true)` and then calls `memory_reset_peak_usage()`, so a spike in one
request does not make the server recycle the worker after every later one. That reset exists on
PHP 8.2+, which is the SDK's floor; a version below the stated minimum would leave the figure cumulative, so a
`memory_limit_mb` threshold recycles more eagerly there. The same figure is available between
requests via the `report` control.

## Example

[`example/worker.php`](example/worker.php) is a runnable worker with `/`, `/health`, `/echo`,
`/big`, `/boom` and `/recycle` routes — including a route that throws (to show that a failing
request does not kill the process) and one that asks to be recycled. `/echo` also echoes the raw
request body back, which is how the pool tests confirm a body arrived as bytes rather than merely
arrived.

To drive it by hand you need something that speaks the protocol; the test below does exactly
that, over a real pipe. It lives in the server repository, because it is the Rust side that
drives this PHP file — clone
[`sailantis/volucer`](https://github.com/sailantis/volucer) and run it there:

```bash
cargo test -p volucer-worker --test php_worker
```

And the server side of the contract — spawning, handshaking, recycling, shutdown — is covered
against real worker processes by:

```bash
cargo test -p volucer-server --test worker_pool
```

Those tests are also the honest answer to "how do you know it works": a Rust client writes
REQUEST frames into a live `php worker.php` and reads the RESPONSE back, so both implementations
are held to the same wire format with a real process in between. They skip themselves, with a
printed reason, when no `php` is on `PATH`.

[`example/supervisor.php`](example/supervisor.php) is a runnable supervisor, so the two files can be
compared directly:

```bash
php example/supervisor.php unix:/tmp/workers.sock
```

It serves `/`, `/health` and `/boom` (which throws, to show that one failing request does not take
the supervisor with it). `/health` reports the pid and how long ago the master booted — ask it twice
and you get two pids and the same boot time, which is the fork working.

## Tests

```bash
php tests/run.php          # 410 assertions, no PHPUnit needed
```

- `tests/WireFormatTest.php` pins the header layout, CRC-32C and the **exact golden bytes** —
  the same literals that `crates/volucer-worker/tests/wire_format.rs` in the
  [`sailantis/volucer`](https://github.com/sailantis/volucer) repository asserts, so if
  either implementation drifts, one of the two suites fails.
- `tests/WorkerTest.php` drives a `Worker` over in-memory streams: output capture, chunking,
  request bodies, control frames, error recovery, and the framing failures.
- `tests/SupervisorTest.php` runs a **real supervisor** and speaks the protocol to it over a real
  `AF_UNIX` socket, because every failure mode `Supervisor` has is a process failure: a child that
  inherits the listener and never closes it, a child that never exits so the slot never comes back,
  a zombie per connection, a socket file left behind for the next start. It also covers the recycle
  race — a peer that sends `CONTROL drain` and closes without reading the ack, which is what
  `Slot::stop()` does on every attached recycle. The fork half is skipped on Windows, where `pcntl`
  does not exist; the pure half (`relayPath()`, the argument checks, the refusal) runs everywhere.

Running the suite prints a `WARNING-MARKER` line on stderr. That is the mechanism working, not a
failure: the warning went to stderr instead of into a frame.

## Requirements and versions

- PHP **8.2+** — the floor is `memory_reset_peak_usage()`, which the SDK calls to make each `END`
  report that request's peak rather than a figure that never falls (see above); `readonly`
  constructor promotion is the syntax floor and is older. Verified passing the full suite on
  **8.2.23, 8.3.31, 8.4.24 and 8.5.9**. PHP 8.0 and 8.1 are below the floor and untested; 8.0
  additionally fails to parse the SDK.
- `ext-hash` for `hash('crc32c', …)` — that is how the checksum is computed without
  reimplementing the polynomial, and it matches `crc32c` in Rust exactly. With the checksum
  option below turned on, the frame codec skips the hash entirely; nothing else in the SDK uses
  `ext-hash`, so a deployment that always turns it off could in principle drop the extension.
- `ext-json`.
- **ext-pcntl**, for `Volucer\Supervisor` only. `Worker` needs nothing beyond `ext-hash` and
  `ext-json`, and a `worker` backend needs no extension at all; the supervisor is the one part that
  cannot exist without `fork()`, and it says so rather than degrading to one process when the
  extension is missing.

The protocol version is `1`. A worker and a server that disagree are caught at the handshake
rather than on the first request.

### Skipping the checksum

A server may offer, in its `HELLO`, to let the worker stop computing the per-frame CRC-32C. The
SDK accepts only when the field is the JSON literal `true` — a `1`, a `"yes"` or a missing key all
read as "no", because a field that switches off an integrity check should take exactly one
spelling — and it echoes the answer in its `HELLO_ACK`. Both ends verify the handshake itself, so
the exchange that carries the agreement is protected; only the frames after it are unchecked.

Nothing has to be configured here: an older server sends no such field and the worker keeps
checksumming. And a server that sends an unchecked frame to a worker that never agreed gets a
protocol error rather than a silent downgrade. See
[`docs/worker-protocol.md`](https://github.com/sailantis/volucer/blob/main/docs/worker-protocol.md)
for the wire details.

The build is separate from the protocol version and travels in the handshake's `runtime` field as
`volucer-worker-php/<build>`, so which build a worker is running is visible from the server rather than
only from the host. It is deliberately not a protocol change: `runtime` is a free-form string, so a server
that ignores it behaves exactly as before.

`Worker::build()` asks Composer about this package **by name** — the three cases that produces:

| Situation | `build()` | Why |
| --- | --- | --- |
| Installed as a release | `0.1.1+7c6945f` | The version names it, the commit pins it exactly |
| A `dev-main` requirement | `7c6945f` | No meaningful version, so the commit *is* the build |
| No Composer at all | `unknown` | A bare `php worker.php` never went through Composer |

It is asked by name rather than through `getRootPackage()`, which returns the **consuming application** —
that would report the host's build under the worker's own name, which is a plausible-looking answer and the
wrong one. The last row is not a failure: a worker that cannot name its build can still serve requests, and
should not refuse to start over a diagnostic.

There is deliberately no `Worker::VERSION` constant, and no tag-based check in the suite. While the API is
settling, `main` is the artifact, and a version literal would be one more thing to keep true by hand on
every change. The commit identifies the build, and it is the same reference a consuming application's
`composer.lock` pins — so what a worker reports is what the application can be reproduced from.

The protocol version and the build move independently: a fix that changes no wire behaviour is a new commit,
while a wire change is a protocol version *and* a release.

The SDK relies on `stream_get_contents($stream, $n)` for exact reads, which blocks until it has
`$n` bytes or reaches EOF and handles partial reads internally. That is deliberate: hand-rolling
an `fread` loop is the bug-prone part of every pipe protocol, and this call is why the SDK does
not contain one.

## License

MIT — see [LICENSE](LICENSE).
