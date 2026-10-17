<?php

declare(strict_types=1);

/**
 * A minimal volucer worker.
 *
 * Run it directly — there is nothing to install:
 *
 *     php example/worker.php
 *
 * It will print a handshake banner to stderr and then wait, doing nothing,
 * because a worker speaks the protocol over stdin/stdout rather than HTTP. To
 * drive it you need the server (`volucer serve`) or the protocol test in
 * `crates/volucer-worker/tests/php_worker.rs` of the `sailantis/volucer`
 * repository.
 *
 * Nothing in here is volucer-specific except `run()`: this is an ordinary PHP
 * file that happens to stay resident. Note that it `echo`s and returns `null` in
 * places, which works because the SDK captures output and uses it as the body —
 * an application does not have to be rewritten to become worker-safe.
 */

require __DIR__ . '/../autoload.php';

use Volucer\Request;
use Volucer\Response;
use Volucer\Worker;

// `connect()` is `create()` plus one decision: when the server has asked for the socket
// transport (`transport = "socket"`, or `VOLUCER_WORKER_SOCKET` set directly) the
// protocol runs over that loopback socket; otherwise over stdin/stdout as before. Writing
// `Worker::create()` here would still work on a pipe, but would ignore the transport the
// server chose and hang waiting for input that is now arriving on a socket.
$worker = Worker::connect();

// The gRPC half, registered before the loop starts. A call is identified by its *content type*, so one
// process serves both protocols and this only decides who answers a call — see `Worker::onGrpc`.
//
// The server never parses a message (`docs/plugins.md` §6.6), so this handler frames its own reply:
// gRPC's message framing is one compression flag byte, a big-endian `u32` length, then that many bytes.
// A real application would let a protobuf library write that; here it is written out so the test has
// something to assert on and so the framing is visible.
$worker->onGrpc(static function (Request $request, Worker $worker): \Volucer\GrpcResponse {
    $method = $request->grpcCalledMethod();

    // An unlisted method is a normal answer, not an error: it is what the application says when it
    // does not implement something the server was told it might.
    if ($method !== 'SayHello') {
        return \Volucer\GrpcResponse::fail(
            \Volucer\GrpcResponse::UNIMPLEMENTED,
            "no method {$method} on this service",
        );
    }

    // The message, framed. `$request->body` is the raw framed bytes the client sent — nothing here
    // parses them, because the server does not either.
    $payload = "hello from volucer's gRPC handler\n";

    return \Volucer\GrpcResponse::message(
        "\x00" . pack('N', strlen($payload)) . $payload,
    );
});

$worker->run(static function (Request $request, Worker $worker): Response|null {
    $path = (string) parse_url($request->uri, PHP_URL_PATH);

    // Health: return a Response explicitly.
    if ($path === '/health') {
        return Response::json(['status' => 'ok', 'pid' => getmypid()]);
    }

    // Telemetry travels on its own channel, so it never touches the response.
    // Telemetry travels on its own channel, so it never touches the response. The name is a
    // Prometheus one -- letters, digits and underscores -- because that is what it becomes; a dot
    // would be refused by the server and the sample would silently do nothing.
    $worker->emitMetric('example_request_total', 1.0, 'count');
    $worker->emitLog("serving {$request->method} {$request->uri}");

    // Echo case: print something and return null. The captured output becomes
    // the response body, so a plain PHP script needs no changes at all.
    //
    // The raw body is echoed back too — not something a real application should
    // do with untrusted input, but this route exists to be probed, and seeing the
    // bytes is the only way to confirm a body arrived intact rather than merely
    // arriving.
    if ($path === '/echo') {
        echo 'you asked for ', htmlspecialchars($request->uri, ENT_QUOTES), "\n";
        echo 'method: ', $request->method, "\n";
        echo 'body: ', strlen($request->body), " bytes\n";
        echo 'payload: ', $request->body, "\n";

        return null;
    }

    // Which headers arrived, as the CGI environment rebuilt them. Worth having as
    // a route: a header that a front-end forwards but the SDK misnames is
    // invisible until an application looks for it by the name it expects.
    if ($path === '/headers') {
        echo 'host: ', htmlspecialchars((string) $request->header('Host'), ENT_QUOTES), "\n";
        echo 'x-marker: ', htmlspecialchars((string) $request->header('X-Marker'), ENT_QUOTES), "\n";

        return null;
    }

    // Ask the server to replace this worker once the request is done. The SDK
    // reports `keep_alive = false` in END and the pool boots a fresh process,
    // which is what an application does when it has put itself into a state it
    // cannot clean up: a leaked global, a stuck connection, a fat static cache.
    if ($path === '/recycle') {
        $worker->recycle();

        return Response::json(['recycled' => true, 'pid' => getmypid()]);
    }

    // A deliberately broken route, to show that one failing request does not
    // take the worker with it: the SDK turns it into a 500 and keeps serving.
    if ($path === '/boom') {
        throw new RuntimeException('this route always fails');
    }

    // A body larger than one protocol chunk, so a reader can confirm the whole
    // thing arrives: chunking is where a length/flag disagreement would show up.
    if ($path === '/big') {
        return Response::text(str_repeat('abcdefgh', 100000));
    }

    return Response::html(
        '<h1>volucer worker</h1>'
        . '<p>Method ' . htmlspecialchars($request->method, ENT_QUOTES) . '</p>'
        . '<p>URI ' . htmlspecialchars($request->uri, ENT_QUOTES) . '</p>'
        . '<p>PHP ' . PHP_VERSION . ' on ' . PHP_SAPI . '</p>'
    );
});
