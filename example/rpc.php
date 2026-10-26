<?php

declare(strict_types=1);

/**
 * A FastCGI application using the call plane.
 *
 * This is the counterpart of `example/worker.php`, and the difference between the two files is the
 * whole point of `[rpc]`: a worker is *handed* its calls on the stream that carries its requests,
 * while this script is served by `php-cgi` — it exists for one request, the server drives it, and the
 * only thing it can do unprompted is dial a socket. So it dials one.
 *
 * Run it under volucer with:
 *
 * ```toml
 * [server]
 * http            = "127.0.0.1:8080"
 * document_root   = "path/to/example"
 * default_backend = "app"
 *
 * [[server.routes]]
 * path_suffix = ".php"
 * backend     = "app"
 *
 * [backends.app]
 * kind     = "spawn_cgi"
 * binary   = "/usr/bin/php-cgi"
 * workers  = 8
 * services = ["kv.cache", "lock"]     # the same grant a worker would name
 *
 * [kv.cache]
 * driver = "memory"
 *
 * [lock]
 * driver = "memory"
 *
 * [rpc]
 * listen = "unix:/run/volucer/rpc.sock"
 * ```
 *
 * Then `curl http://127.0.0.1:8080/rpc.php`. Nothing in this file names an address: volucer puts
 * `VOLUCER_RPC` and `VOLUCER_RPC_TOKEN` into the request it hands `php-cgi`, and `Rpc::connect()`
 * reads them.
 *
 * **Without `[rpc]`, or without `services` on this backend, this file still runs.** It gets a plane
 * with no address, and the first call raises with a message naming the missing variable — which is
 * the state a request that never touches a store is perfectly happy in.
 */

require __DIR__ . '/../autoload.php';

use Volucer\ProtocolException;
use Volucer\Response;
use Volucer\Rpc;

$rpc = Rpc::connect();

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Nothing on this one is a diagnostic any more, so it is left plain: `Rpc` and `Lock` document
// themselves, and a comment here is about what the *deployment* had to do rather than about the API.
header('Content-Type: text/plain; charset=utf-8');

$lines = [];
$lines[] = 'call plane: ' . ($rpc->address() ?? '(none: this backend granted nothing)');

if ($path === '/rpc.php/cache') {
    // The storage half. `Kv` takes a serializer pair, and defaults to JSON — so an application that
    // cached an array gets its array back, and the server held opaque bytes either way.
    $kv = $rpc->kv('cache');

    $kv->set('greeting', ['text' => 'hello from a FastCGI process', 'at' => date(DATE_ATOM)], 60);

    $found = null;
    $value = $kv->get('greeting', $found);
    $lines[] = 'found:   ' . ($found ? 'yes' : 'no');
    $lines[] = 'value:   ' . json_encode($value, JSON_THROW_ON_ERROR);
}

if ($path === '/rpc.php/lock') {
    // The coordination half. A lock is for collapsing work — two requests rendering the same PDF —
    // and not for protecting an invariant; `Lock`'s class documentation says why a lease is not a
    // fencing token. `acquire()` returns the id that holds it, or null for contention, which is an
    // ordinary answer rather than an error.
    $id = $rpc->lock()->acquire('example:render', 0, 0);

    $lines[] = $id === null
        ? 'lock:    held by somebody else'
        : 'lock:    acquired as ' . $id;

    if ($id !== null) {
        $rpc->lock()->release('example:render', $id);
        $lines[] = 'released';
    }
}

if ($path === '/rpc.php/boom') {
    // A refusal, caught and shown. `[rpc]` refuses three ways — no token, an unknown token, a method
    // the backend's `services` does not grant — and all three arrive as an exception with the reason
    // spelled out. `lastCallWasUnknown()` is the third answer and it is *not* the same as a refusal:
    // here nothing was sent, so it is false, and retrying would be safe.
    try {
        $rpc->kv('not-granted')->get('anything');
    } catch (ProtocolException $exception) {
        $lines[] = 'refused: ' . $exception->getMessage();
        $lines[] = 'unknown: ' . ($rpc->lastCallWasUnknown() ? 'yes' : 'no');
    }
}

echo implode("\n", $lines), "\n";
