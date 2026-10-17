<?php

declare(strict_types=1);

/**
 * gRPC mode: what a handler says, and where it has to end up.
 *
 * The server is the side that knows gRPC's wire format — the SDK's whole job here is to let an
 * application *state* an outcome without knowing it, and to state it in the one place the worker
 * protocol allows: two response headers, which the server moves into the trailer section. So what
 * these tests assert is exactly that boundary, and the frame stream is the right place to assert it
 * because it is what the server actually reads.
 *
 * The second half is the routing rule, which exists twice — here and in the server — for the same
 * reason the server keeps one implementation of it: if the two drifted, this worker and that server
 * would disagree about which service a call addressed, and the disagreement would be invisible.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\GrpcResponse;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\Request;
use Volucer\Worker;

/**
 * Encode a sequence of `[type, request_id, payload_or_array, flags]` frames into a stream.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
 * @return resource
 */
function grpc_frames(array $frames)
{
    $bytes = '';
    foreach ($frames as $frame) {
        $payload = $frame[2] ?? '';
        if (is_array($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $bytes .= (new Frame($frame[0], $frame[1], (string) $payload, $frame[3] ?? 0))->encode();
    }

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** @return resource */
function grpc_sink()
{
    return fopen('php://memory', 'r+b');
}

/** @param resource $stream */
function grpc_drained($stream): string
{
    rewind($stream);
    $bytes = stream_get_contents($stream);

    return $bytes === false ? '' : $bytes;
}

/**
 * Decode every frame a worker wrote, verifying each checksum.
 *
 * Reimplemented rather than shared with `WorkerTest.php`, because that file is a *script* rather than
 * a library — requiring it would run its assertions twice. The duplication is a few lines of header
 * arithmetic, and the alternative would be a shared file whose only purpose is to be included twice.
 *
 * @return list<array{header: Header, payload: string}>
 */
function grpc_parse(string $bytes): array
{
    $frames = [];
    $offset = 0;
    $length = strlen($bytes);

    while ($offset + Header::HEADER_LEN <= $length) {
        $header = Header::decode(substr($bytes, $offset, Header::HEADER_LEN));
        $offset += Header::HEADER_LEN;
        $payload = substr($bytes, $offset, $header->payloadLen);
        $offset += $header->payloadLen;

        if (! $header->declaresNoChecksum() && Frame::crc32c($payload) !== $header->checksum) {
            throw new RuntimeException('the worker wrote a frame with a bad checksum');
        }

        $frames[] = ['header' => $header, 'payload' => $payload];
    }

    return $frames;
}

/**
 * Serve one gRPC call with `$handler` and return the RESPONSE head, the body and the diagnostics.
 *
 * The handshake is real: a HELLO is fed first, exactly as a server sends one, so this drives the same
 * path a deployment does. The fixtures' frames are checksummed and verified here, so a worker that
 * wrote garbage fails at the codec rather than in an assertion about gRPC.
 *
 * @param callable(Request, Worker): mixed $handler
 * @return array{status: int, headers: array<string, string>, body: string, stderr: string, raw: string}
 */
function serve_grpc(
    string $uri,
    string $body,
    callable $handler,
    string $contentType = 'application/grpc+proto',
): array {
    $frames = [
        [MessageType::HELLO, 0, ['protocol_version' => 1]],
        [MessageType::REQUEST, 1, [
            'method' => 'POST',
            'uri' => $uri,
            'protocol' => 'HTTP/2.0',
            'headers' => [['content-type', $contentType], ['te', 'trailers']],
            'remote_addr' => '203.0.113.7',
            'script_filename' => '',
            'body_len' => strlen($body),
            'https' => false,
        ]],
    ];
    // Only when there is one. The protocol declares `body_len` in the head, so a zero-length body is
    // the *absence* of frames rather than an empty `BODY` — and a stray one is a protocol error the
    // worker is right to refuse. `WorkerTest.php`'s `a_get` follows the same rule.
    if ($body !== '') {
        $frames[] = [MessageType::BODY, 1, $body, Header::FLAG_LAST];
    }

    $stream = grpc_frames($frames);
    $out = grpc_sink();
    $err = grpc_sink();

    (new Worker($stream, $out, $err))->grpc($handler);
    fclose($stream);

    $raw = grpc_drained($out);

    $head = null;
    $body = '';
    foreach (grpc_parse($raw) as $frame) {
        if ($frame['header']->messageType === MessageType::RESPONSE) {
            $head = json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ($frame['header']->messageType === MessageType::BODY) {
            $body .= $frame['payload'];
        }
    }

    if (! is_array($head)) {
        throw new RuntimeException('the worker did not write a RESPONSE head: ' . $raw);
    }

    $headers = [];
    foreach ($head['headers'] as [$name, $value]) {
        $headers[strtolower((string) $name)] = (string) $value;
    }

    return [
        'status' => (int) $head['status'],
        'headers' => $headers,
        'body' => $body,
        'stderr' => grpc_drained($err),
        'raw' => $raw,
    ];
}

// --------------------------------------------------------------- the outcome

$answer = serve_grpc(
    '/greeter.Greeter/SayHello',
    "\x00\x00\x00\x00\x05hello",
    static fn (): GrpcResponse => GrpcResponse::message('REPLY'),
);
Assert::same(
    200,
    $answer['status'],
    'a gRPC reply is an HTTP 200; the outcome is not the status line',
);
Assert::same('REPLY', $answer['body'], 'a successful message is the body, byte for byte');
Assert::same(
    '0',
    $answer['headers']['grpc-status'] ?? null,
    'the status is stated as a response header, which is the only place the protocol has for it',
);
Assert::same(
    'application/grpc+proto',
    $answer['headers']['content-type'] ?? null,
    'and the media type a gRPC client requires before it will read the body',
);

// A failure states its code and its reason, and the reason is *not* the body: a failed call has no
// payload, and the sentence belongs in the trailer beside the status.
$answer = serve_grpc(
    '/greeter.Greeter/SayHello',
    '',
    static fn (): GrpcResponse => GrpcResponse::fail(GrpcResponse::NOT_FOUND, 'no such user'),
);
Assert::same('5', $answer['headers']['grpc-status'] ?? null, 'NOT_FOUND is 5');
Assert::same('no such user', $answer['headers']['grpc-message'] ?? null, 'and the reason travels beside it');
Assert::same('', $answer['body'], 'a failed call carries no payload');

// Success with no message sends no `grpc-message` at all, because an empty one and an absent one mean
// the same thing to a client and only one of them is true.
$answer = serve_grpc('/a.B/M', '', static fn (): GrpcResponse => GrpcResponse::ok());
Assert::same(false, isset($answer['headers']['grpc-message']), 'a message-less success sends no grpc-message');
Assert::same('0', $answer['headers']['grpc-status'] ?? null, 'and still states its status');

// A handler returning a bare string is sending message bytes, which is the shape a handler that only
// encodes a reply needs.
$answer = serve_grpc('/a.B/M', '', static fn (): string => "\x00\x00\x00\x00\x02hi");
Assert::same("\x00\x00\x00\x00\x02hi", $answer['body'], 'a returned string is the body, framing and all');

// `null` is an empty message, not a failure.
$answer = serve_grpc('/a.B/M', '', static fn (): null => null);
Assert::same('', $answer['body'], 'null is an empty message');
Assert::same('0', $answer['headers']['grpc-status'] ?? null, 'and a successful one');

// The request reaches the handler with its method path and its bytes, like any other request: a gRPC
// handler needs to know which method it was asked for, and it needs the message it was sent.
$seen = null;
$answer = serve_grpc(
    '/greeter.Greeter/SayHello',
    "\x00\x00\x00\x00\x05world",
    static function (Request $request) use (&$seen): GrpcResponse {
        $seen = [
            'method' => $request->grpcCalledMethod(),
            'service' => $request->grpcService(),
            'isGrpc' => $request->isGrpc(),
            'body' => $request->body,
        ];

        return GrpcResponse::ok();
    },
);
Assert::same(
    [
        'method' => 'SayHello',
        'service' => 'greeter.Greeter',
        'isGrpc' => true,
        'body' => "\x00\x00\x00\x00\x05world",
    ],
    $seen,
    'the handler receives the method path, the framing flag and the message bytes',
);

// A thrown handler is INTERNAL, and the exception is *reported* rather than only translated: the
// status says nothing about what went wrong, so the diagnostic is the only record of it.
$answer = serve_grpc(
    '/a.B/M',
    '',
    static function (): never {
        throw new RuntimeException('the handler is broken');
    },
);
Assert::same('13', $answer['headers']['grpc-status'] ?? null, 'an uncaught exception is INTERNAL');
Assert::contains(
    'the handler is broken',
    $answer['stderr'],
    'and the exception reaches stderr, because the status alone says nothing about it',
);

// A handler that returns something else is a programming error, and it is reported as one rather than
// guessed at. **On a gRPC call it is still an HTTP 200 with `INTERNAL` in the headers**, not a 500: gRPC
// reports every outcome in the trailer section, so a 500 would be a status the client cannot read. The
// diagnostic on stderr is what says which type the handler should have returned.
$answer = serve_grpc('/a.B/M', '', static fn (): array => ['not', 'a', 'response']);
Assert::same(
    200,
    $answer['status'],
    'a broken handler on a call is a 200 with a status in the headers, never a 500',
);
Assert::same(
    '13',
    $answer['headers']['grpc-status'] ?? null,
    'and the status is INTERNAL, which is what gRPC means by a failure the server cannot describe',
);
Assert::contains(
    'must return a GrpcResponse',
    $answer['stderr'],
    'and says which type it should have been, because that is the only place the mistake is named',
);

// --------------------------------------------------------------- the routing rule

/**
 * A request for `$uri`, for exercising the accessors without a worker.
 */
function grpc_request(string $uri, string $contentType = 'application/grpc+proto'): Request
{
    return new Request(
        method: 'POST',
        uri: $uri,
        protocol: 'HTTP/2.0',
        headers: ['content-type' => $contentType],
        remoteAddr: '203.0.113.7',
        scriptFilename: '',
        https: false,
        body: '',
    );
}

$call = grpc_request('/greeter.Greeter/SayHello');
Assert::same(['greeter.Greeter', 'SayHello'], $call->grpcMethod(), 'the path splits into a service and a method');
Assert::same('greeter.Greeter', $call->grpcService(), 'the service is available alone');
Assert::same('SayHello', $call->grpcCalledMethod(), 'and so is the method');

// A package with several components is still one service name, and an underscore is legal protobuf.
Assert::same(
    ['a.b.c.v1_2', 'Get_Thing'],
    grpc_request('/a.b.c.v1_2/Get_Thing')->grpcMethod(),
    'a multi-component package is one service name',
);

// What is *not* a method path, and each case is a way a lenient answer would hand an application
// something that is not a method name.
$notAMethod = [
    '/greeter.Greeter' => 'no method at all',
    '/greeter.Greeter/' => 'an empty method',
    '/greeter.Greeter/Say/Hello' => 'a third segment',
    '/greeter.Greeter/SayHello?x=1' => 'a query string is not stripped',
    '/greeter.Greeter/SayHello#x' => 'nor is a fragment',
    'greeter.Greeter/SayHello' => 'no leading slash',
    '/' => 'nothing',
    '/9greeter.Greeter/M' => 'a service cannot start with a digit',
    '/a-B/M' => 'a hyphen is not an identifier byte',
    '/a.B/M-x' => 'nor in a method',
    '/greeter..Greeter/M' => 'an empty package part',
    '/.greeter.Greeter/M' => 'nor a leading dot',
];
foreach ($notAMethod as $path => $why) {
    Assert::same(null, grpc_request($path)->grpcMethod(), "$path is not a method path: $why");
}

// The media type is judged exactly as the server judges it. `application/grpcx` is the case that
// matters: a bare prefix test accepts it, and it is a *different* type that begins with the same
// letters.
Assert::true(grpc_request('/a.B/M', 'application/grpc')->isGrpc(), 'the bare media type');
Assert::true(grpc_request('/a.B/M', 'application/grpc+json')->isGrpc(), 'a suffix');
Assert::true(grpc_request('/a.B/M', 'Application/GRPC')->isGrpc(), 'case-insensitively');
Assert::true(grpc_request('/a.B/M', ' application/grpc ')->isGrpc(), 'and around whitespace');
Assert::same(
    false,
    grpc_request('/a.B/M', 'application/grpcx')->isGrpc(),
    'a prefix match is not a match: application/grpcx is a different media type',
);
Assert::same(false, grpc_request('/a.B/M', 'application/json')->isGrpc(), 'nor is another type');

// A method-shaped path does not make a request a call, and this is the distinction that keeps an HTTP
// route from becoming a service without anyone deciding that.
$route = grpc_request('/greeter.Greeter/SayHello', 'text/html');
Assert::same(
    false,
    $route->isGrpc(),
    'a method-shaped path with an HTML content type is an HTML route, not a gRPC call',
);
Assert::same(
    ['greeter.Greeter', 'SayHello'],
    $route->grpcMethod(),
    'though the path still splits, because reading it is a separate question',
);

exit(Assert::report('GrpcTest'));
