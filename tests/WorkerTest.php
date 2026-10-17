<?php

declare(strict_types=1);

/**
 * Worker behaviour, driven without a subprocess.
 *
 * The [`Worker`] is constructed over in-memory streams, so a test can write the
 * exact frames a server would send and then inspect the exact frames the worker
 * wrote. These are the fast, precise tests; the process-level ones live in the
 * Rust integration test, where a real pipe and a real server are involved.
 *
 * The tests that matter most assert on the *raw bytes* rather than on parsed
 * frames — `echo inside a handler never reaches the frame stream` and the warning
 * test — because the failure they guard against is application output landing
 * inside a frame, which by definition would make parsing the frames the wrong
 * thing to do.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\Request;
use Volucer\Response;
use Volucer\Worker;

/**
 * Encode a sequence of `[type, request_id, payload_or_array, flags]` frames into
 * a stream positioned at the start.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
 * @return resource
 */
function server_stream(array $frames)
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
function sink()
{
    return fopen('php://memory', 'r+b');
}

/** Read everything written to a memory stream. */
function drained($stream): string
{
    rewind($stream);
    $bytes = stream_get_contents($stream);

    return $bytes === false ? '' : $bytes;
}

/**
 * Decode every frame in a byte string, verifying each checksum.
 *
 * A frame that declares `FLAG_NO_CHECKSUM` is accepted with a zero checksum field instead,
 * which is the one thing the flag is allowed to mean. The *reader* decides, exactly as
 * `Worker::readPayload` does: this helper mirrors the worker's rule so a test can decode a
 * stream the worker wrote after agreeing to skip the hash.
 *
 * @return list<array{header: Header, payload: string}>
 */
function parse_frames(string $bytes): array
{
    $frames = [];
    $offset = 0;
    $length = strlen($bytes);

    while ($offset < $length) {
        $header = Header::decode(substr($bytes, $offset, Header::HEADER_LEN));
        $offset += Header::HEADER_LEN;
        $payload = substr($bytes, $offset, $header->payloadLen);
        $offset += $header->payloadLen;

        if ($header->declaresNoChecksum()) {
            if ($header->checksum !== 0) {
                throw new RuntimeException('a frame declared no checksum but carried one');
            }
        } elseif (Frame::crc32c($payload) !== $header->checksum) {
            throw new RuntimeException('the worker wrote a frame with a bad checksum');
        }

        $frames[] = ['header' => $header, 'payload' => $payload];
    }

    return $frames;
}

/**
 * The **raw** payloads of the frames with a given type.
 *
 * Raw, not decoded: a BODY payload is application bytes and need not be JSON, so
 * decoding here would destroy exactly what the interesting assertions look at.
 *
 * @param list<array{header: Header, payload: string}> $frames
 * @return list<string>
 */
function payloads_of(array $frames, int $type): array
{
    $out = [];
    foreach ($frames as $frame) {
        if ($frame['header']->messageType === $type) {
            $out[] = $frame['payload'];
        }
    }

    return $out;
}

/**
 * The decoded JSON payloads of the frames with a given type.
 *
 * @param list<array{header: Header, payload: string}> $frames
 * @return list<array<string, mixed>>
 */
function json_of(array $frames, int $type): array
{
    return array_map(
        static fn (string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
        payloads_of($frames, $type),
    );
}

/**
 * Run a worker to completion over in-memory streams.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames the server's frames
 * @param callable(Request, Worker): mixed $handler
 * @return array{frames: list<array{header: Header, payload: string}>, raw: string, stderr: string}
 */
function run_worker(array $frames, callable $handler): array
{
    $in = server_stream($frames);
    $out = sink();
    $err = sink();

    (new Worker($in, $out, $err))->run($handler);

    $raw = drained($out);

    return [
        'frames' => parse_frames($raw),
        'raw' => $raw,
        'stderr' => drained($err),
    ];
}

/** A HELLO, as the server sends first. */
const A_HELLO = [MessageType::HELLO, 0];

/** A HELLO that offers the worker the option of omitting the checksum. */
const A_OFFERED_HELLO = [MessageType::HELLO, 0, ['protocol_version' => 1, 'no_checksum' => true]];

/** A GET request, with no body. */
function a_get(string $uri = '/', int $id = 1): array
{
    return [[MessageType::REQUEST, $id, [
        'method' => 'GET',
        'uri' => $uri,
        'protocol' => 'HTTP/1.1',
        'headers' => [['Accept', 'text/html'], ['Host', 'example.test']],
        'remote_addr' => '127.0.0.1',
        'script_filename' => 'index.php',
        'body_len' => 0,
        'https' => false,
    ]]];
}

// ---------------------------------------------------------------- handshake

$handshake = run_worker([A_HELLO], static fn () => Response::text('unused'));
$acks = json_of($handshake['frames'], MessageType::HELLO_ACK);
Assert::same(1, count($acks), 'the worker answers a HELLO with exactly one HELLO_ACK');
Assert::same(1, $acks[0]['protocol_version'], 'the HELLO_ACK declares protocol version 1');
Assert::same(PHP_VERSION, $acks[0]['php_version'], 'the HELLO_ACK reports PHP_VERSION');
Assert::same(PHP_SAPI, $acks[0]['sapi'], 'the HELLO_ACK reports PHP_SAPI');
Assert::true($acks[0]['pid'] > 0, 'the HELLO_ACK reports a real pid');
Assert::same(
    'volucer-worker-php/' . Worker::build(),
    $acks[0]['runtime'],
    'the HELLO_ACK identifies the SDK and the build it is running',
);
Assert::same(0, $handshake['frames'][0]['header']->requestId, 'the HELLO_ACK is unsolicited (request id 0)');

// The build is asked of the install rather than written down, so the only
// invariant is that the answer is never empty: a worker must always be able to
// say *something*, and `unknown` is the honest something when Composer is not
// there to ask.
Assert::true(Worker::build() !== '', 'the build is never empty');
Assert::true(
    preg_match('/^[A-Za-z0-9._+-]+$/', Worker::build()) === 1,
    'the build is a single safe token, suitable for a log line',
);

// A mismatched server version is refused at boot, not on the first request.
Assert::throws(
    static function (): void {
        $in = server_stream([[MessageType::HELLO, 0, ['protocol_version' => 9]]]);
        (new Worker($in, sink(), sink()))->run(static fn () => Response::text('x'));
    },
    'a server speaking an unknown protocol version is refused at the handshake',
    'protocol version 9',
);

// ------------------------------------------------------- the checksum option

// A server that does not mention the field is answered with an explicit `false`,
// not with silence: the SDK states what it agreed to, so a server can record it.
$plain = run_worker([A_HELLO], static fn () => Response::text('unused'));
Assert::same(
    false,
    json_of($plain['frames'], MessageType::HELLO_ACK)[0]['no_checksum'],
    'a HELLO that does not offer the option is answered with no_checksum false',
);

// The offer accepted: the HELLO_ACK still carries a real checksum, because both
// ends are verifying at the moment the agreement is exchanged, and every frame
// *after* it is written with the flag raised and a zero checksum field.
$offered = run_worker(
    array_merge([A_OFFERED_HELLO], a_get('/x')),
    static fn () => Response::text('body'),
);
Assert::same(
    true,
    json_of($offered['frames'], MessageType::HELLO_ACK)[0]['no_checksum'],
    'the offer is echoed back in the HELLO_ACK',
);
$ack = $offered['frames'][0]['header'];
Assert::same(0, $ack->flags & Header::FLAG_NO_CHECKSUM, 'the HELLO_ACK itself is still checksummed');

$bodyFrame = null;
foreach ($offered['frames'] as $frame) {
    if ($frame['header']->messageType === MessageType::BODY) {
        $bodyFrame = $frame;
    }
}
Assert::true($bodyFrame !== null, 'the offered worker still answered with a body');
Assert::same(
    Header::FLAG_NO_CHECKSUM,
    $bodyFrame['header']->flags & Header::FLAG_NO_CHECKSUM,
    'a frame written after the agreement declares that it has no checksum',
);
Assert::same(0, $bodyFrame['header']->checksum, 'and the checksum field is zero');
Assert::same('body', $bodyFrame['payload'], 'the payload itself is untouched');

// A frame that claims no checksum on a worker that never agreed is refused. This
// is the rule that stops a server from turning the check off unilaterally.
Assert::throws(
    static function (): void {
        $in = server_stream([
            [MessageType::HELLO, 0, ['protocol_version' => 1, 'no_checksum' => false]],
            [MessageType::REQUEST, 1, ['method' => 'GET', 'uri' => '/', 'body_len' => 0], Header::FLAG_NO_CHECKSUM],
        ]);
        (new Worker($in, sink(), sink()))->run(static fn () => Response::text('x'));
    },
    'a frame declaring no checksum is refused when the option was never agreed',
    'never agreed to',
);

// And a refused offer is not a crash: the worker keeps verifying and serves.
$refused = run_worker(
    array_merge([A_OFFERED_HELLO], a_get('/kept')),
    static fn (Request $request) => Response::text('kept ' . $request->uri),
);
Assert::same(
    ['kept /kept'],
    payloads_of($refused['frames'], MessageType::BODY),
    'a worker that agreed to the option still serves normally',
);

// The offer is echoed only when it is the JSON literal `true`: a truthy string
// must not be read as agreement, or a sloppy server could switch the check off.
foreach ([1, '1', 'yes', 'on', 'true', null] as $notAnOffer) {
    $in = server_stream([[MessageType::HELLO, 0, ['protocol_version' => 1, 'no_checksum' => $notAnOffer]]]);
    $out = sink();
    (new Worker($in, $out, sink()))->run(static fn () => Response::text('unused'));
    $answer = json_decode(parse_frames(drained($out))[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
    Assert::same(
        false,
        $answer['no_checksum'],
        'only the JSON literal true counts as consent, not ' . var_export($notAnOffer, true),
    );
}

// --------------------------------------------------------- a simple request

$result = run_worker(
    array_merge([A_HELLO], a_get('/hello')),
    static fn (Request $request) => Response::text('hi ' . $request->uri),
);

$responseHeads = json_of($result['frames'], MessageType::RESPONSE);
$ends = json_of($result['frames'], MessageType::END);

Assert::same(1, count($responseHeads), 'one RESPONSE is written');
Assert::same(200, $responseHeads[0]['status'], 'the status is 200');
Assert::same(9, $responseHeads[0]['body_len'], 'the RESPONSE declares the real body length');
Assert::same(1, count($ends), 'one END terminates the request');
Assert::true($ends[0]['keep_alive'], 'the worker stays alive by default');
Assert::same(0, $ends[0]['app_status'], 'a successful request reports app_status 0');
Assert::same(['hi /hello'], payloads_of($result['frames'], MessageType::BODY), 'the body arrives intact');

foreach ($result['frames'] as $frame) {
    if (in_array($frame['header']->messageType, [MessageType::RESPONSE, MessageType::BODY, MessageType::END], true)) {
        Assert::same(1, $frame['header']->requestId, 'every correlated frame carries the request id');
    }
}
$bodyFrames = array_values(array_filter(
    $result['frames'],
    static fn ($f) => $f['header']->messageType === MessageType::BODY,
));
Assert::true($bodyFrames[0]['header']->isLast(), 'the final body frame carries LAST');

// ---------------------------------------------------------- an empty body

$empty = run_worker(
    array_merge([A_HELLO], a_get('/empty')),
    static fn () => new Response(204, [], ''),
);
Assert::same(
    [MessageType::HELLO_ACK, MessageType::RESPONSE, MessageType::END],
    array_map(static fn ($f) => $f['header']->messageType, $empty['frames']),
    'an empty body sends no BODY frame at all',
);

// --------------------------------------------------- rule 1: output capture

// The critical one. A handler that echoes *and* returns a Response must not leak
// its echo into the frame stream.
$leaky = run_worker(
    array_merge([A_HELLO], a_get('/leak')),
    static function (): Response {
        echo 'LEAKED-MARKER';

        return Response::text('the real body');
    },
);
Assert::true(
    !str_contains($leaky['raw'], 'LEAKED-MARKER'),
    'echo inside a handler never reaches the frame stream',
);
Assert::same(
    ['the real body'],
    payloads_of($leaky['frames'], MessageType::BODY),
    'the returned Response body is what is sent',
);

// The echo case: returning null makes the captured output the body, which is what
// lets a plain PHP script run unchanged as a worker.
$echoed = run_worker(
    array_merge([A_HELLO], a_get('/echo')),
    static function () {
        echo 'printed by the application';

        return null;
    },
);
Assert::same(
    ['printed by the application'],
    payloads_of($echoed['frames'], MessageType::BODY),
    'a null return uses the captured output as the body',
);
Assert::same(
    strlen('printed by the application'),
    json_of($echoed['frames'], MessageType::RESPONSE)[0]['body_len'],
    'the declared body_len matches the captured output',
);

// The second half of rule 1: a warning must not be captured into the body either.
// `display_errors=stderr` is what makes that hold, and `Worker::create` sets it,
// so mirror it here rather than trusting the test process's own setting.
//
// Running this test prints `WARNING-MARKER` on stderr. That is the mechanism
// working, not a failure: the warning went to stderr instead of into a frame.
$previousDisplayErrors = ini_get('display_errors');
ini_set('display_errors', 'stderr');

$warned = run_worker(
    array_merge([A_HELLO], a_get('/warn')),
    static function () {
        trigger_error('WARNING-MARKER', E_USER_WARNING);
        echo 'body after the warning';

        return null;
    },
);

ini_set('display_errors', is_string($previousDisplayErrors) ? $previousDisplayErrors : '1');

Assert::true(
    !str_contains($warned['raw'], 'WARNING-MARKER'),
    'a warning never reaches the frame stream',
);
Assert::same(
    ['body after the warning'],
    payloads_of($warned['frames'], MessageType::BODY),
    'a warning is not captured into the response body',
);

// ------------------------------------------------------- a failing handler

// A handler exception becomes a 500 and the worker *keeps serving*: one bad
// request must not take the worker down.
$recovered = run_worker(
    array_merge([A_HELLO], a_get('/boom', 1), a_get('/fine', 2)),
    static function (Request $request): Response {
        if ($request->uri === '/boom') {
            throw new RuntimeException('handler exploded');
        }

        return Response::text('still here');
    },
);

$recoveredHeads = json_of($recovered['frames'], MessageType::RESPONSE);
$recoveredEnds = json_of($recovered['frames'], MessageType::END);
Assert::same(2, count($recoveredHeads), 'both requests are answered');
Assert::same(500, $recoveredHeads[0]['status'], 'the failing request becomes a 500');
Assert::same(1, $recoveredEnds[0]['app_status'], 'the failing request reports app_status 1');
Assert::same(200, $recoveredHeads[1]['status'], 'the next request is served normally');
Assert::same(0, $recoveredEnds[1]['app_status'], 'the second request reports app_status 0');
Assert::true($recoveredEnds[1]['keep_alive'], 'the worker does not ask to be replaced after a handler error');

// ------------------------------------------- a handler that leaks a buffer

// A handler can leave an output buffer open. The stream must still be aligned,
// and this request's leftovers must not become the next request's body.
$unbalanced = run_worker(
    array_merge([A_HELLO], a_get('/one', 1), a_get('/two', 2)),
    static function (Request $request) {
        if ($request->uri === '/one') {
            ob_start();
            echo 'leftover from request one';

            return Response::text('one done');
        }

        echo 'two';

        return null;
    },
);
$unbalancedBodies = payloads_of($unbalanced['frames'], MessageType::BODY);
Assert::true(
    !in_array('leftover from request one', $unbalancedBodies, true),
    'a leaked buffer never becomes a response body',
);
Assert::same(['one done', 'two'], $unbalancedBodies, 'both requests produce exactly their own bodies');

// ------------------------------------------------------------- body chunking

$big = str_repeat('abcdefgh', 70000); // 560000 bytes: nine chunks of 65536
$chunked = run_worker(array_merge([A_HELLO], a_get('/big')), static fn () => Response::text($big));

$chunkFrames = array_values(array_filter(
    $chunked['frames'],
    static fn ($f) => $f['header']->messageType === MessageType::BODY,
));
Assert::same(
    (int) ceil(strlen($big) / Worker::MAX_BODY_CHUNK),
    count($chunkFrames),
    'a large body is split into MAX_BODY_CHUNK chunks',
);
$reassembled = '';
foreach ($chunkFrames as $index => $frame) {
    $reassembled .= $frame['payload'];
    if ($index < count($chunkFrames) - 1) {
        Assert::true(!$frame['header']->isLast(), 'only the final chunk carries LAST');
    }
}
Assert::true($chunkFrames[count($chunkFrames) - 1]['header']->isLast(), 'the final chunk carries LAST');
Assert::same($big, $reassembled, 'the chunks reassemble to the original body');
Assert::same(
    strlen($big),
    json_of($chunked['frames'], MessageType::RESPONSE)[0]['body_len'],
    'body_len matches the chunked body',
);

// ----------------------------------------------------------- request bodies

$withBody = run_worker(
    array_merge(
        [A_HELLO],
        [[MessageType::REQUEST, 1, [
            'method' => 'POST',
            'uri' => '/submit?x=1',
            'protocol' => 'HTTP/1.1',
            'headers' => [['Content-Type', 'application/json'], ['Host', 'example.test']],
            'remote_addr' => '127.0.0.1',
            'body_len' => 7, // '{"a":1}'
            'script_filename' => 'index.php',
            'https' => false,
        ]]],
        [[MessageType::BODY, 1, '{"a":', 0]],
        [[MessageType::BODY, 1, '1}', Header::FLAG_LAST]],
    ),
    static fn (Request $request) => Response::json([
        'body' => $request->body,
        'content_type' => $request->contentType(),
        'query' => $request->query(),
        'method' => $request->method,
        'host' => $request->serverVars()['HTTP_HOST'] ?? 'missing',
    ]),
);
$withBodyPayload = json_decode(
    payloads_of($withBody['frames'], MessageType::BODY)[0],
    true,
    512,
    JSON_THROW_ON_ERROR,
);
Assert::same('{"a":1}', $withBodyPayload['body'], 'a body split across frames is reassembled');
Assert::same('application/json', $withBodyPayload['content_type'], 'headers are readable case-insensitively');
Assert::same(['x' => '1'], $withBodyPayload['query'], 'the query string is parsed');
Assert::same('POST', $withBodyPayload['method'], 'the method is carried through');
Assert::same('example.test', $withBodyPayload['host'], 'request headers become HTTP_* server variables');

// Content-Type keeps its CGI name rather than becoming HTTP_CONTENT_TYPE, which
// is what the server sends a php-cgi backend and what the CGI specification
// says. A framework asking for the content type would otherwise see none.
$server_vars = (static function (): array {
    $request = new Request(
        method: 'POST',
        uri: '/x?y=1',
        protocol: 'HTTP/1.1',
        headers: ['Content-Type' => 'application/json', 'Content-Length' => '7', 'X-Custom' => 'yes'],
        remoteAddr: '127.0.0.1',
        scriptFilename: 'index.php',
        https: false,
        body: '{"a":1}',
    );

    return $request->serverVars();
})();
Assert::same('application/json', $server_vars['CONTENT_TYPE'] ?? 'missing', 'Content-Type keeps its CGI name');
Assert::same(null, $server_vars['HTTP_CONTENT_TYPE'] ?? null, 'Content-Type does not become HTTP_CONTENT_TYPE');
Assert::same('yes', $server_vars['HTTP_X_CUSTOM'] ?? 'missing', 'other headers still become HTTP_*');
Assert::same('7', $server_vars['CONTENT_LENGTH'] ?? 'missing', 'CONTENT_LENGTH is the framed body length');
Assert::same(null, $server_vars['HTTP_CONTENT_LENGTH'] ?? null, 'Content-Length does not become HTTP_CONTENT_LENGTH');

// $_COOKIE. Nothing fills it in a worker, so the mapping has to exist for an
// application — or for the session id PHP would have read out of it.
$cookies = (static function (): array {
    $request = new Request(
        method: 'GET',
        uri: '/',
        protocol: 'HTTP/1.1',
        headers: ['Cookie' => '_s=abc%3D123; theme=dark; _s=ignored'],
        remoteAddr: '127.0.0.1',
        scriptFilename: 'index.php',
        https: false,
        body: '',
    );

    return $request->cookies();
})();
Assert::same(
    ['_s' => 'abc=123', 'theme' => 'dark'],
    $cookies,
    'the Cookie header is parsed the way PHP populates $_COOKIE, first value winning',
);
Assert::same(
    [],
    (new Request('GET', '/', 'HTTP/1.1', [], '', 'index.php', false, ''))->cookies(),
    'no Cookie header is no cookies',
);
Assert::same(
    [],
    (new Request('GET', '/', 'HTTP/1.1', ['Cookie' => '  ; ;=x'], '', 'index.php', false, ''))->cookies(),
    'malformed cookie pairs are skipped rather than guessed at',
);

// A body that overshoots its declared length is refused: the declared length is
// authoritative, and exceeding it means the two ends disagree about the framing.
Assert::throws(
    static function (): void {
        run_worker(
            array_merge(
                [A_HELLO],
                [[MessageType::REQUEST, 1, [
                    'method' => 'POST', 'uri' => '/', 'protocol' => 'HTTP/1.1',
                    'headers' => [], 'remote_addr' => '', 'body_len' => 5,
                    'script_filename' => '', 'https' => false,
                ]]],
                [[MessageType::BODY, 1, 'toolong', Header::FLAG_LAST]],
            ),
            static fn () => Response::text('x'),
        );
    },
    'a LAST body frame that overshoots the declared length is refused',
    'declared bytes',
);

// -------------------------------------------------------------- ping/control

$pinged = run_worker([A_HELLO, [MessageType::PING, 0]], static fn () => Response::text('x'));
Assert::same(1, count(payloads_of($pinged['frames'], MessageType::PONG)), 'a PING is answered with a PONG');
Assert::same(0, count(payloads_of($pinged['frames'], MessageType::RESPONSE)), 'a PING is not a request');

$drainedWorker = run_worker(
    [A_HELLO, [MessageType::CONTROL, 0, ['kind' => 'drain']]],
    static fn () => Response::text('x'),
);
Assert::same(
    [MessageType::HELLO_ACK, MessageType::CONTROL_ACK],
    array_map(static fn ($f) => $f['header']->messageType, $drainedWorker['frames']),
    'a drain is acknowledged and then the worker stops',
);

$shut = run_worker([A_HELLO, [MessageType::CONTROL, 0, ['kind' => 'shutdown']]], static fn () => Response::text('x'));
Assert::same(
    [MessageType::HELLO_ACK, MessageType::CONTROL_ACK],
    array_map(static fn ($f) => $f['header']->messageType, $shut['frames']),
    'a shutdown is acknowledged and then the worker stops',
);
Assert::same(
    0,
    count(payloads_of($shut['frames'], MessageType::END)),
    'a shutdown writes no END: END is request-scoped and no request was in flight',
);

// A clean EOF — the server closing stdin — ends the loop without an error. This
// is rule 2: shutdown by construction, with no signal to catch.
$eof = run_worker([A_HELLO], static fn () => Response::text('x'));
Assert::same(1, count(payloads_of($eof['frames'], MessageType::HELLO_ACK)), 'a clean EOF is not an error');

// A report is asked for as a control and answered with a metric, not a request.
$reported = run_worker(
    [A_HELLO, [MessageType::CONTROL, 0, ['kind' => 'report']]],
    static fn () => Response::text('x'),
);
$reportedMetrics = json_of($reported['frames'], MessageType::METRIC);
Assert::same(1, count($reportedMetrics), 'a report answers with one metric');
Assert::same('worker.peak_memory_bytes', $reportedMetrics[0]['name'], 'the report names its metric');

// ------------------------------------------------------------- stream errors

// A truncated payload is a framing failure, not a clean close.
Assert::throws(
    static function (): void {
        $in = server_stream([A_HELLO, [MessageType::REQUEST, 1, ['method' => 'GET', 'uri' => '/', 'body_len' => 0]]]);
        $bytes = drained($in);
        $cut = fopen('php://memory', 'r+b');
        fwrite($cut, substr($bytes, 0, strlen($bytes) - 5));
        rewind($cut);
        (new Worker($cut, sink(), sink()))->run(static fn () => Response::text('x'));
    },
    'a stream that ends mid-payload is a protocol error',
    'payload bytes',
);

// A tampered payload fails the checksum. This is the check that turns "output
// leaked into the stream" into a clear error rather than a silent corruption.
Assert::throws(
    static function (): void {
        $bytes = (new Frame(MessageType::HELLO, 0))->encode()
            . (new Frame(MessageType::REQUEST, 1, '{"method":"GET","uri":"/","body_len":0}'))->encode();
        $bytes[strlen($bytes) - 3] = "\x00";
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        (new Worker($stream, sink(), sink()))->run(static fn () => Response::text('x'));
    },
    'a corrupted payload fails the checksum',
    'checksum mismatch',
);

// The server sending something only a worker may send is a protocol error.
Assert::throws(
    static function (): void {
        run_worker([A_HELLO, [MessageType::RESPONSE, 1, ['status' => 200]]], static fn () => Response::text('x'));
    },
    'a RESPONSE from the server is refused',
    'cannot serve',
);

// ------------------------------------------------------- the create() factory

$savedDisplayErrors = ini_get('display_errors');
Worker::create(server_stream([A_HELLO]), sink());
Assert::same(
    'stderr',
    ini_get('display_errors'),
    'Worker::create sends diagnostics to stderr, never into the frame stream',
);
ini_set('display_errors', is_string($savedDisplayErrors) ? $savedDisplayErrors : '1');

// -------------------------------------------------------------- recycle()

$recycled = run_worker(
    array_merge([A_HELLO], a_get('/recycle')),
    static function (Request $request, Worker $worker): Response {
        $worker->recycle();

        return Response::text('done');
    },
);
Assert::true(
    !json_of($recycled['frames'], MessageType::END)[0]['keep_alive'],
    'recycle() reports keep_alive=false so the server replaces the worker',
);

// Telemetry goes out unsolicited, at request id 0, never inside a response.
$telemetry = run_worker(
    array_merge([A_HELLO], a_get('/log')),
    static function (Request $request, Worker $worker): Response {
        $worker->emitLog('hello from the handler');
        $worker->emitMetric('handler.count', 1.0, 'count');

        return Response::text('ok');
    },
);
$logFrames = array_values(array_filter(
    $telemetry['frames'],
    static fn ($f) => $f['header']->messageType === MessageType::LOG,
));
Assert::same(1, count($logFrames), 'a log record is emitted');
Assert::same(0, $logFrames[0]['header']->requestId, 'a log record is unsolicited (request id 0)');
$decodedLog = json_decode($logFrames[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
Assert::same('hello from the handler', $decodedLog['message'], 'the log message survives');
Assert::same(1, $decodedLog['request_id'], 'the log record records which request it belongs to');

// ------------------------------------------------------- peak memory reporting

// `memory_get_peak_usage(true)` is a high-water mark that begins at process
// start, so a worker that peaked once would report a figure above a configured
// `memory_limit_mb` on every later request, and the server would recycle it every
// time. The END figure is therefore read and then re-baselined, which makes it a
// per-request measurement.
$twoRequests = run_worker(
    array_merge([A_HELLO], a_get('/first', 1), a_get('/second', 2)),
    static function (Request $request): Response {
        if ($request->uri === '/first') {
            // Big enough to move `memory_get_peak_usage(true)` on any version,
            // and freed before the request ends, so "usage now" is unchanged.
            $spike = str_repeat('x', 16 * 1024 * 1024);
            unset($spike);
        }

        return Response::text('ok');
    },
);

$peakEnds = json_of($twoRequests['frames'], MessageType::END);
Assert::same(2, count($peakEnds), 'two requests produce two END frames');
Assert::true(
    $peakEnds[0]['peak_memory_bytes'] > 0,
    'a worker reports a positive peak resident set',
);

if (\function_exists('memory_reset_peak_usage')) {
    Assert::true(
        $peakEnds[1]['peak_memory_bytes'] < $peakEnds[0]['peak_memory_bytes'],
        'the peak is re-baselined after each request, so one spike does not follow the worker for ever',
    );
} else {
    Assert::same(
        $peakEnds[0]['peak_memory_bytes'],
        $peakEnds[1]['peak_memory_bytes'],
        'without memory_reset_peak_usage (PHP < 8.2) the reported figure stays monotonic',
    );
}

// The `report` control reads the same figure and resets it too, so an operator
// sampling between requests cannot resurrect a spike the last request cleared.
$afterReport = run_worker(
    array_merge([A_HELLO], a_get('/spike', 3), [[MessageType::CONTROL, 0, ['kind' => 'report']]]),
    static function (Request $request): Response {
        $spike = str_repeat('y', 16 * 1024 * 1024);
        unset($spike);

        return Response::text('ok');
    },
);
$spikeEnd = json_of($afterReport['frames'], MessageType::END)[0]['peak_memory_bytes'];
$reported = json_of($afterReport['frames'], MessageType::METRIC);
Assert::same(1, count($reported), 'a report answers with one metric');
if (\function_exists('memory_reset_peak_usage')) {
    Assert::true(
        $reported[0]['value'] < $spikeEnd,
        'a report after a large request measures the baseline, not the cleared spike',
    );
} else {
    Assert::same((float) $spikeEnd, $reported[0]['value'], 'without a reset the report repeats the peak');
}

// `Worker::connect()` is `create()` plus one decision, and the decision is worth
// testing without a socket: every interesting case is *absence*, and every one of
// them has to mean stdio rather than "connect to nowhere". A worker that dialled
// `tcp:// ` would fail at startup on a host where stdio was perfectly fine.
$addressCases = [
    'unset' => null,
    'empty' => '',
    'only whitespace' => '   ',
    'set' => '127.0.0.1:18400',
];
foreach ($addressCases as $case => $value) {
    if ($value === null) {
        putenv(Worker::SOCKET_ENV);
    } else {
        putenv(Worker::SOCKET_ENV . '=' . $value);
    }

    $expected = $value === null ? null : trim($value);
    $found = Worker::socketAddress();
    Assert::same(
        $expected === '' ? null : $expected,
        $found,
        "the socket address is read from the environment ($case)",
    );
}
// Leave the environment as the surrounding tests found it: a stray value here would
// make a later `connect()` dial an address nobody is listening on.
putenv(Worker::SOCKET_ENV);

// And the fallback itself: with nothing set, `connect()` must produce a worker bound
// to stdio rather than throwing or dialling. It is not driven with a handshake here
// because a real process-level check exists in the Rust integration test; what this
// pins is the branch, which is the part that a missing `connect()` would break.
Assert::same(
    null,
    Worker::socketAddress(),
    'with the variable cleared, the transport is stdio',
);

/**
 * A `CALL` from the server is answered, and the request it interrupted still completes.
 *
 * The frames go in as: a `CALL` mid-response, then the rest of the response. The frame order is the
 * point — a call arrives *between* a response's head and its end, and the server is still owed the
 * response afterwards.
 */
function call_then_response(): array
{
    return [
        A_HELLO,
        [MessageType::REQUEST, 1, [
            'method' => 'GET',
            'uri' => '/',
            'protocol' => 'HTTP/1.1',
            'headers' => [],
            'remote_addr' => '127.0.0.1',
            'body_len' => 0,
            'script_filename' => '/index.php',
            'https' => false,
        ]],
        // The call arrives after the request is under way. In a real deployment the server writes it
        // while the worker is mid-request and the worker reads it at the top of its next loop, which is
        // why the `RETURN` below is asserted to exist rather than to be in any particular position
        // relative to the response the worker writes.
        [MessageType::CALL, 0xFFFFFFFF, [
            'name' => 'kv.get',
            'params' => ['key' => 'session:1'],
        ]],
    ];
}

$called = [];
$result = (function () use (&$called) {
    // The worker is driven with a handler registered, which is what a real application does.
    $in = server_stream(call_then_response());
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $worker->onCall(function (array $call) use (&$called) {
        $called[] = $call;
        return ['value' => 'abc'];
    });
    $worker->run(static fn () => Response::text('ok'));

    return ['frames' => parse_frames(drained($out)), 'stderr' => drained($err)];
})();

Assert::same('kv.get', $called[0]['name'] ?? null, 'the handler sees the call name');
Assert::same(['key' => 'session:1'], $called[0]['params'] ?? null, 'and its params');

$returns = json_of($result['frames'], MessageType::RETURN);
Assert::same(1, count($returns), 'exactly one RETURN was written');

// The `CALL` in the fixture is a frame the *server* wrote, so it must not appear among the frames
// the worker wrote back. This assertion is what caught the opposite claim, which is worth recording
// because it is the trap in this direction: the worker *answers* a call and *originates* nothing, so
// a test that looks for a `CALL` in the worker's output is asserting on the wrong side of the
// connection and would pass against an empty stream.
Assert::same(
    0,
    count(payloads_of($result['frames'], MessageType::CALL)),
    'a worker never writes a CALL; the server is the only side that sends one',
);
Assert::same(['value' => 'abc'], $returns[0]['result'] ?? null, 'with the handler value as the result');
Assert::same(true, $returns[0]['ok'] ?? null, 'and ok');

// The response is still complete, which is the property that makes a call safe during a request.
$ends = json_of($result['frames'], MessageType::END);
Assert::same(1, count($ends), 'and the interrupted request still ended');

/**
 * With no handler registered, the call is **refused, not ignored**.
 *
 * This is the one assertion in this file that guards a hang: a dropped `CALL` leaves the server waiting
 * until its own deadline, and in the server's pool a deadline is a kill.
 */
$noHandler = (function () {
    $in = server_stream(call_then_response());
    $out = sink();
    $err = sink();
    (new Worker($in, $out, $err))->run(static fn () => Response::text('ok'));

    return json_of(parse_frames(drained($out)), MessageType::RETURN);
})();

Assert::same(1, count($noHandler), 'a RETURN still goes out');
Assert::same(false, $noHandler[0]['ok'] ?? null, 'refusing the call');
Assert::true(
    str_contains((string) ($noHandler[0]['error'] ?? ''), 'no call handler'),
    'and saying why: ' . ($noHandler[0]['error'] ?? '(nothing)'),
);

/**
 * A handler may report that the call's effect is **unknown**, which is not the same as refusing it.
 *
 * The server retries a refusal and does not retry an unknown state, because retrying something that may
 * have happened duplicates a side effect. If the SDK dropped the flag the two would be indistinguishable.
 */
$ambiguous = (function () {
    $in = server_stream(call_then_response());
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $worker->onCall(static fn (array $call) => [
        'ok' => false,
        'unknown' => true,
        'error' => 'the lock backend timed out',
    ]);
    $worker->run(static fn () => Response::text('ok'));

    return json_of(parse_frames(drained($out)), MessageType::RETURN);
})();

Assert::same(false, $ambiguous[0]['ok'] ?? null, 'the call is not ok');
Assert::same(true, $ambiguous[0]['unknown'] ?? null, 'and it is explicitly ambiguous');
Assert::same(false, $noHandler[0]['unknown'] ?? false, 'whereas a refusal is not');

/**
 * A handler that throws refuses the call rather than killing the worker.
 *
 * The loop is mid-request, so the server is still owed both the `RETURN` and the response. Propagating
 * would exit the process and lose the request as well, which is strictly worse than refusing one call.
 */
$threw = (function () {
    $in = server_stream(call_then_response());
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $worker->onCall(static function (array $call): never {
        throw new RuntimeException('a handler bug');
    });
    $worker->run(static fn () => Response::text('ok'));

    $raw = drained($out);

    return [
        'frames' => parse_frames($raw),
        'stderr' => drained($err),
    ];
})();

$thrown = json_of($threw['frames'], MessageType::RETURN);
Assert::same(false, $thrown[0]['ok'] ?? null, 'a throwing handler refuses the call');
Assert::true(
    str_contains((string) ($thrown[0]['error'] ?? ''), 'RuntimeException'),
    'naming the exception: ' . ($thrown[0]['error'] ?? '(nothing)'),
);
Assert::same(false, $thrown[0]['unknown'] ?? true, 'and does not claim the effect is unknown');
Assert::true(
    str_contains($threw['stderr'], 'a handler bug'),
    'the exception reaches stderr, not the frame stream',
);
Assert::same(1, count(json_of($threw['frames'], MessageType::END)), 'and the request still ended');

/**
 * A worker **makes** a call from inside a request handler and reads the answer.
 *
 * The other half of the call plane, and the half nothing exercised before: the tests above drive a
 * `CALL` the server sent, while this drives one the worker sends. It is `Worker::call()`, and what it
 * has to get right is that the answer is *correlated* — read by a small loop that skips past frames
 * which are not this call's answer — and that the response the worker was in the middle of is
 * unaffected.
 *
 * The fixture sends the `RETURN` only after an unrelated `PING` and an unrelated `CALL`, so a client
 * that accepted the first frame it read would return the wrong thing and a client that could not nest
 * a dispatch would deadlock. Both are what this is for.
 */
function a_call_during_a_request(): array
{
    return [
        A_HELLO,
        [MessageType::REQUEST, 1, [
            'method' => 'GET',
            'uri' => '/',
            'protocol' => 'HTTP/1.1',
            'headers' => [],
            'remote_addr' => '127.0.0.1',
            'body_len' => 0,
            'script_filename' => '/index.php',
            'https' => false,
        ]],
        // A PING first: not this call's answer, and the worker must answer it and keep reading.
        [MessageType::PING, 0],
        // Then another CALL: also not the answer, and the worker answers it with its own RETURN.
        [MessageType::CALL, 0xFFFFFFFE, [
            'name' => 'config.describe',
            'params' => [],
        ]],
        // Only now the answer to the call the handler made.
        [MessageType::RETURN, 0xFFFFFFFF, [
            'ok' => true,
            'result' => ['value' => 'from the server'],
        ]],
    ];
}

$made = (function () {
    $in = server_stream(a_call_during_a_request());
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    // The worker also answers calls, so that the nested CALL in the fixture is not left unanswered.
    $worker->onCall(static fn (array $call) => ['described' => true]);
    $seen = null;
    $worker->run(static function ($request, Worker $worker) use (&$seen) {
        $seen = $worker->call('kv.get', ['key' => 'session:1']);

        return Response::text('ok');
    });

    return [
        'frames' => parse_frames(drained($out)),
        'seen' => $seen,
        'stderr' => drained($err),
    ];
})();

Assert::same(
    ['value' => 'from the server'],
    $made['seen'],
    'the answer to the call is returned to the handler: ' . $made['stderr'],
);

$written = $made['frames'];
$callIds = [];
foreach ($written as $frame) {
    if ($frame['header']->messageType === MessageType::CALL) {
        $callIds[] = $frame['header']->requestId;
    }
}
Assert::same(
    [0xFFFFFFFF],
    $callIds,
    'the worker wrote exactly one CALL, on an id from the top of the range (so it cannot be mistaken '
    . 'for a request id, which counts up from 1)',
);
Assert::same(
    1,
    count(payloads_of($written, MessageType::PONG)),
    'and it answered the PING it had to read past',
);

// The unrelated `CALL` from the fixture is answered too — that is what makes this a dispatch loop and
// not a skip — and it is answered on *its own* id, not on the id of the call the handler made.
$returns = [];
foreach ($written as $frame) {
    if ($frame['header']->messageType === MessageType::RETURN) {
        $returns[] = $frame['header']->requestId;
    }
}
Assert::same(
    [0xFFFFFFFE],
    $returns,
    'the CALL that arrived mid-call was answered on its own id, so the worker is never confused '
    . 'about which call a frame belongs to',
);

// And the request the handler was serving still completed, which is the property that lets a call
// happen at all: the response exchange is untouched by everything above.
Assert::same(
    1,
    count(json_of($written, MessageType::END)),
    'the interrupted request still ended normally',
);

/**
 * `Worker::kv()`: the encoding the client does, and the answers it reads back.
 *
 * The KV client is thin and `Worker::call` is tested above; what is under test here is the *encoding* —
 * a value is serialized and base64-encoded on this side, the key the same way, and a result decoded back
 * into whatever went in — plus the three-state answers, which are the parts a caller cannot see from the
 * method signature.
 *
 * The fixture cannot read while the worker writes, so every answer is written ahead of time on the ids
 * the client will use: `0xFFFFFFFF` for its first call and counting down. That is also what makes this a
 * test of the client rather than of the fixture.
 */
function kv_fixture(array $answers): array
{
    $frames = [
        A_HELLO,
        [MessageType::REQUEST, 1, [
            'method' => 'GET',
            'uri' => '/',
            'protocol' => 'HTTP/1.1',
            'headers' => [],
            'remote_addr' => '127.0.0.1',
            'body_len' => 0,
            'script_filename' => '/index.php',
            'https' => false,
        ]],
    ];
    // One RETURN per answer, on the ids the client's own counter will hand out.
    foreach ($answers as $index => $answer) {
        $frames[] = [MessageType::RETURN, 0xFFFFFFFF - $index, ['ok' => true] + $answer];
    }

    return $frames;
}

/**
 * Run one request whose handler uses a `Kv` handle, and report what it saw and what it sent.
 *
 * @param callable(\Volucer\Kv): mixed $body
 * @return array{result: mixed, calls: list<array{header: Header, payload: array<string, mixed>}>}
 */
function run_kv(array $answers, callable $body, string $storage = 'sessions'): array
{
    $in = server_stream(kv_fixture($answers));
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $result = null;
    $worker->run(static function ($request, Worker $worker) use ($body, $storage, &$result) {
        $result = $body($worker->kv($storage));

        return Response::text('ok');
    });

    $calls = [];
    foreach (parse_frames(drained($out)) as $frame) {
        if ($frame['header']->messageType === MessageType::CALL) {
            $calls[] = [
                'header' => $frame['header'],
                'payload' => json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR),
            ];
        }
    }

    return ['result' => $result, 'calls' => $calls];
}

// ------------------------------------------------------------------------------------------------
// A value goes in, comes back, and is the same value — asserted on the bytes that crossed the wire as
// well as on the result, because a client that encoded correctly and decoded wrongly would pass a test
// that only compared the handler's return value against the fixture's echo.

$roundtrip = run_kv(
    [
        // One answer per call: the `set` then the `get`. The first is consumed and ignored — `set` reads
        // only `stored` from it — which is also what makes the assertion below about the *second* answer
        // rather than about a fixture that echoed whatever it was given.
        ['result' => ['stored' => true]],
        ['result' => ['value' => base64_encode('{"hello":"world"}')]],
    ],
    static function ($kv) {
        $kv->set('greeting', ['hello' => 'world']);

        return $kv->get('greeting');
    },
);

Assert::same(
    'kv.sessions.set',
    $roundtrip['calls'][0]['payload']['name'] ?? null,
    'the storage is part of the method name, because the server routes on it',
);
Assert::same(
    'greeting',
    base64_decode($roundtrip['calls'][0]['payload']['params']['key'] ?? '', true),
    'the key reached the wire as the caller wrote it, through the same base64 as a value',
);
Assert::same(
    '{"hello":"world"}',
    base64_decode($roundtrip['calls'][0]['payload']['params']['value'] ?? '', true),
    'and the value is the default serializer\u2019s JSON, unaltered by the encoding layer',
);
Assert::same(
    ['hello' => 'world'],
    $roundtrip['result'],
    'the answer decodes back into the array that went in',
);

// ------------------------------------------------------------------------------------------------
// A stored `null` is not the same as a key that was never written — the distinction PSR-16's `get`
// cannot make, and the reason `Kv::get` takes a `$found` reference rather than returning null for both.

$nulls = run_kv(
    [
        ['result' => ['value' => base64_encode('null')]],
        ['result' => ['value' => null]],
    ],
    static function ($kv) {
        $stored = $kv->get('stored-null', $present);
        $absent = $kv->get('never-written', $missing);

        return ['stored' => $stored, 'present' => $present, 'absent' => $absent, 'missing' => $missing];
    },
);

Assert::same(
    ['stored' => null, 'present' => true, 'absent' => null, 'missing' => false],
    $nulls['result'],
    'a stored null reports itself present and an unwritten key does not, even though both are null',
);

// ------------------------------------------------------------------------------------------------
// The three-state TTL, which must not collapse into one number: a client comparing `seconds > 0` would
// read "never expires" as "expired".

$ttls = run_kv(
    [
        ['result' => ['ttl' => 'missing']],
        ['result' => ['ttl' => 'forever']],
        ['result' => ['ttl' => 60]],
    ],
    static fn ($kv) => [
        'missing' => $kv->ttl('never-written'),
        'forever' => $kv->ttl('forever'),
        'soon' => $kv->ttl('soon'),
    ],
);

Assert::same(
    ['missing' => 'missing', 'forever' => 'forever', 'soon' => 60],
    $ttls['result'],
    'each of the three answers arrives as its own thing',
);

// ------------------------------------------------------------------------------------------------
// A zero TTL means delete, and is sent as a delete rather than as a `set` with a count the wire cannot
// carry — PSR-16: "a negative or zero TTL MUST cause the item to be deleted".

foreach ([0, -1] as $ttl) {
    $zero = run_kv([], static function ($kv) use ($ttl) {
        $kv->set('key', 'value', $ttl);

        return null;
    });

    Assert::same(
        'kv.sessions.delete',
        $zero['calls'][0]['payload']['name'] ?? null,
        "a TTL of {$ttl} must become a delete",
    );
}

// And no TTL at all omits the parameter rather than sending a zero, which would be a delete.
$forever = run_kv([], static function ($kv) {
    $kv->set('key', 'value');

    return null;
});

Assert::same(
    false,
    array_key_exists('ttl', $forever['calls'][0]['payload']['params'] ?? []),
    'omitting the TTL must omit the parameter, not send a zero that the server would read as a delete',
);

// ------------------------------------------------------------------------------------------------
// The serializer is replaceable, which is the reason the server carries opaque bytes rather than PHP
// values. Asserted on the bytes, because that is what proves the server never sees a structured value.

$serialized = run_kv([['result' => ['value' => base64_encode('serialized-bytes')]]], static function ($kv) {
    $kv->serializer(serialize(...), unserialize(...));
    $kv->set('object', new ArrayObject(['a' => 1]));

    return null;
});

$bytes = base64_decode($serialized['calls'][0]['payload']['params']['value'] ?? '', true) ?: '';
Assert::true(
    str_contains($bytes, 'ArrayObject'),
    'the serialized form is PHP\u2019s own and not JSON: ' . var_export($bytes, true),
);

// ------------------------------------------------------------------------------------------------
// A batch keeps its positions, including the holes: a version that dropped absent keys would make the
// third answer belong to the second key, which is how a batch API silently returns the wrong data.

$batch = run_kv(
    [
        ['result' => ['stored' => 2]],
        ['result' => ['values' => [base64_encode('1'), null, base64_encode('3')]]],
    ],
    static function ($kv) {
        $kv->setMultiple(['a' => 1, 'c' => 3]);

        return $kv->getMany(['a', 'missing', 'c']);
    },
);

Assert::same(
    [1, null, 3],
    $batch['result'],
    'the absent key is a hole in its own position',
);
Assert::same(
    'kv.sessions.setMultiple',
    $batch['calls'][0]['payload']['name'] ?? null,
    'and the write was one call rather than one per key',
);
Assert::same(
    2,
    count($batch['calls'][0]['payload']['params']['values'] ?? []),
    'carrying both values',
);

// `getMultiple` names what was missing, because a stored null is a value and cannot be distinguished
// from an absence by a keyed array alone.
$separated = run_kv(
    [['result' => ['values' => [base64_encode('1'), null]]]],
    static function ($kv) {
        $missing = null;
        $found = $kv->getMultiple(['a', 'b'], $missing);

        return ['found' => $found, 'missing' => $missing];
    },
);

Assert::same(
    ['a' => 1],
    $separated['result']['found'],
    'only the keys with values are in the answer',
);
Assert::same(
    ['b'],
    $separated['result']['missing'],
    'and the ones without are named, rather than being indistinguishable from a value of null',
);

// ------------------------------------------------------------------------------------------------
// An empty key is refused here rather than at the server: this side can refuse it before a round trip,
// and its message can say which argument was wrong.

$empty = run_kv([], static function ($kv) {
    return $kv->get('');
});

Assert::same([], $empty['calls'], 'an empty key must not reach the wire');

// ------------------------------------------------------------------------------------------------
// A **refused** call raises rather than looking like a cache miss.
//
// The failure this prevents is the one an application is least able to notice: an ungranted storage, a
// malformed key or a full store leaves `Worker::call` returning null with the reason in `lastCallError`,
// and a client that ignored that would report "not cached" for every read — re-deriving everything on
// every request and looking healthy while doing it. That is the exact failure a cache is deployed to
// prevent, arriving without a log line.
//
// The fixture answers with the server's own shape for a refusal: `ok: false` and an error string.

$fixture = kv_fixture([]);
// Replace the first canned answer with a refusal, which is what the server sends for an ungranted
// storage — see `KvNamespace::resolve` for the message.
$fixture[2] = [MessageType::RETURN, 0xFFFFFFFF, [
    'ok' => false,
    'error' => 'this backend\'s windows may not reach the "secrets" storage; it may reach "sessions"',
]];

$refused = (function () use ($fixture) {
    $in = server_stream($fixture);
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $message = null;
    $worker->run(static function ($request, Worker $worker) use (&$message) {
        try {
            $worker->kv('secrets')->get('key');
        } catch (\Volucer\ProtocolException $e) {
            $message = $e->getMessage();
        }

        return Response::text('ok');
    });

    return $message;
})();

Assert::true(
    is_string($refused) && str_contains($refused, 'refused'),
    'a refused call must raise rather than return a value that reads as a cache miss',
);
Assert::true(
    str_contains((string) $refused, 'sessions'),
    'and the exception carries the server\u2019s message, which names what may be reached: ' . var_export($refused, true),
);

// ------------------------------------------------------------------------------------------------
// Locks. The fixture is the same shape: a request, then one RETURN per answer on the ids the client
// hands out. What these assert is the boundary between the client's units and the wire's, and the
// three outcomes an acquisition has — because two of them are easy to conflate and one of them is
// dangerous to conflate.

/**
 * Run one request whose handler uses the lock plane, reporting the result and the calls it sent.
 *
 * @param callable(\Volucer\Lock): mixed $body
 * @return array{result: mixed, calls: list<array{header: Header, payload: array<string, mixed>}>}
 */
function run_lock(array $answers, callable $body): array
{
    $in = server_stream(kv_fixture($answers));
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $result = null;
    $worker->run(static function ($request, Worker $worker) use ($body, &$result) {
        $result = $body($worker->lock());

        return Response::text('ok');
    });

    $calls = [];
    foreach (parse_frames(drained($out)) as $frame) {
        if ($frame['header']->messageType === MessageType::CALL) {
            $calls[] = [
                'header' => $frame['header'],
                'payload' => json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR),
            ];
        }
    }

    return ['result' => $result, 'calls' => $calls];
}

$acquired = run_lock(
    [['result' => ['acquired' => true, 'id' => 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0', 'mode' => 'exclusive', 'remaining_ms' => null]]],
    static fn ($lock) => $lock->acquire('pdf:create'),
);

Assert::same(
    'lock.acquire',
    $acquired['calls'][0]['payload']['name'] ?? null,
    'the namespace is the whole method name: a lock is named by its resource, so there is nothing to choose',
);
Assert::same(
    'pdf:create',
    $acquired['calls'][0]['payload']['params']['resource'] ?? null,
    'the resource goes out as written',
);
Assert::same(
    0,
    $acquired['calls'][0]['payload']['params']['ttl'] ?? null,
    'a zero TTL goes out as zero rather than being translated into something else',
);
Assert::same(
    'exclusive',
    $acquired['calls'][0]['payload']['params']['mode'] ?? null,
    'an exclusive acquisition says so rather than relying on the server\u2019s default',
);
Assert::same(
    0,
    $acquired['calls'][0]['payload']['params']['wait'] ?? null,
    'and a zero wait goes out, because the server\u2019s default would be the same thing but a caller that meant "one attempt" should be able to see it',
);
Assert::same(
    'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0',
    $acquired['result'],
    'the id the server handed out comes back, and it is the only thing that can release the lock',
);

// ttl and wait are microseconds on the wire, so a caller writing seconds has to convert — and the
// conversion is the client\u2019s job rather than the application\u2019s, which is what RoadRunner does too.
$seconds = run_lock(
    [['result' => ['acquired' => true, 'id' => 'abc', 'mode' => 'shared']]],
    static fn ($lock) => $lock->acquireShared('report:daily', ttl: 30_000_000, wait: 2_500_000),
);
Assert::same(
    30_000_000,
    $seconds['calls'][0]['payload']['params']['ttl'] ?? null,
    'thirty million microseconds reaches the server as thirty million, not as thirty or as thirty thousand',
);
Assert::same(
    2_500_000,
    $seconds['calls'][0]['payload']['params']['wait'] ?? null,
    'and so does the wait',
);
Assert::same(
    'shared',
    $seconds['calls'][0]['payload']['params']['mode'] ?? null,
    'a shared acquisition is a mode rather than a different method, so one verb serves both',
);

// Contention is an ordinary outcome: `null`, and **no exception**. A caller has to be able to tell it
// apart from the third answer, which is why an answer that is present-but-false and an absence of an
// answer are handled in different places.
$contended = run_lock(
    [['result' => ['acquired' => false, 'id' => 'generated-for-the-caller', 'mode' => null, 'remaining_ms' => null]]],
    static fn ($lock) => $lock->tryAcquire('pdf:create'),
);
Assert::same(
    null,
    $contended['result'],
    'a lock somebody else holds comes back as null rather than as an exception',
);
Assert::same(
    'lock.try_acquire',
    $contended['calls'][0]['payload']['name'] ?? null,
    'and tryAcquire is its own method on the wire, so "do not wait" is not expressed as a zero deadline',
);

// `updateTtl` answering false is the opposite of contention, and both are "no": here the caller
// *believed* it held the lock and does not, which is the case a caller has to act on.
$renewed = run_lock(
    [['result' => ['updated' => false, 'id' => 'gone']]],
    static fn ($lock) => $lock->updateTtl('pdf:create', 'gone', 10_000_000),
    // The `false` is what is asserted; the fixture is deliberately a refusal-shaped success.
);
Assert::same(
    false,
    $renewed['result'],
    'renewing a lock the id no longer holds answers false rather than throwing',
);
Assert::same(
    'lock.update_ttl',
    $renewed['calls'][0]['payload']['name'] ?? null,
    'and the verb the server routes on is update_ttl',
);

// The escape hatch, and the read that resolves an unknown state.
$forced = run_lock(
    [
        ['result' => ['released' => true]],
        ['result' => ['exists' => false]],
        ['result' => ['holders' => [['id' => 'w1', 'mode' => 'shared', 'remaining_ms' => 1500]]]],
    ],
    static function ($lock) {
        return [
            'forced' => $lock->forceRelease('pdf:create'),
            'exists' => $lock->exists('pdf:create'),
            'holders' => $lock->holders('pdf:create'),
        ];
    },
);
Assert::same(
    'lock.release_all',
    $forced['calls'][0]['payload']['name'] ?? null,
    'forceRelease is release_all on the wire, because it takes the resource from everyone',
);
Assert::same(true, $forced['result']['forced'], 'and reports whether it took anything');
Assert::same(
    'lock.exists',
    $forced['calls'][1]['payload']['name'] ?? null,
    'exists asks the question that resolves an unknown acquisition',
);
Assert::same(false, $forced['result']['exists'], 'and answers it');
Assert::same(
    [
        ['id' => 'w1', 'mode' => 'shared', 'remaining_ms' => 1500],
    ],
    $forced['result']['holders'],
    'holders is the full answer, so a caller can see who is inside rather than only that somebody is',
);

// A refused lock call raises, and the exception says the state is unknown and what to do about it —
// because a client that reported this as `false` would send the caller into the section anyway.
$refusedLock = (function () {
    $fixture = kv_fixture([]);
    $fixture[2] = [MessageType::RETURN, 0xFFFFFFFF, [
        'ok' => false,
        'error' => 'acquire on "pdf:create" left the lock state unknown: the connection dropped',
        'unknown' => true,
    ]];

    $in = server_stream($fixture);
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $message = null;
    $worker->run(static function ($request, Worker $worker) use (&$message) {
        try {
            $worker->lock()->acquire('pdf:create');
        } catch (\Volucer\ProtocolException $e) {
            $message = $e->getMessage();
        }

        return Response::text('ok');
    });

    return ['message' => $message, 'unknown' => $worker->lastCallWasUnknown()];
})();

Assert::true(
    is_string($refusedLock['message']) && str_contains($refusedLock['message'], 'refused'),
    'an acquisition the server could not decide must raise rather than return null: ' . var_export($refusedLock['message'], true),
);
Assert::true(
    str_contains((string) $refusedLock['message'], 'exists()'),
    'and it must say how to resolve it, because that is the whole point of the third answer: ' . var_export($refusedLock['message'], true),
);

// An empty resource is refused here rather than sent, since the server would refuse it anyway and a
// round trip to learn that a local mistake is a local mistake is a round trip wasted. Called directly
// rather than through a handler: the check happens before the call, so no request is needed to reach it.
$emptyResource = null;
try {
    $probe = new Worker(server_stream([]), sink(), sink());
    $probe->lock()->acquire('');
} catch (\InvalidArgumentException $e) {
    $emptyResource = $e->getMessage();
}
Assert::true(
    is_string($emptyResource) && str_contains($emptyResource, 'resource'),
    'an empty resource is refused by the client, naming the argument',
);

// ------------------------------------------------------------------------------------------------
// Queues: the producer side. Two things are worth asserting here rather than in the Rust tests — that a
// payload's bytes survive base64 exactly, and that a *partial* batch throws with the ids that made it
// rather than reporting a total failure.

/**
 * Run one request whose handler uses a queue handle, reporting the result and the calls it sent.
 *
 * @param callable(\Volucer\Jobs): mixed $body
 * @return array{result: mixed, calls: list<array{header: Header, payload: array<string, mixed>}>}
 */
function run_jobs(array $answers, callable $body, string $pipeline = 'emails'): array
{
    $in = server_stream(kv_fixture($answers));
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $result = null;
    $worker->run(static function ($request, Worker $worker) use ($body, $pipeline, &$result) {
        $result = $body($worker->queue($pipeline));

        return Response::text('ok');
    });

    $calls = [];
    foreach (parse_frames(drained($out)) as $frame) {
        if ($frame['header']->messageType === MessageType::CALL) {
            $calls[] = [
                'header' => $frame['header'],
                'payload' => json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR),
                // The undecoded frame, for assertions about the *bytes* on the wire. `json_decode` with
                // `true` turns an empty JSON object into an empty PHP array, so `{}` and `[]` are
                // indistinguishable after a decode ? and telling them apart is exactly what one assertion
                // here needs, because the server refuses a list where it expects a header map.
                'raw' => $frame['payload'],
            ];
        }
    }

    return ['result' => $result, 'calls' => $calls];
}

$push = run_jobs(
    [['result' => ['pushed' => true, 'id' => 'abc-0']]],
    // Bytes above 0x7f included on purpose: a JSON string cannot carry one, which is the whole reason the
    // payload is base64 on the wire.
    static fn ($jobs) => $jobs->push('App\\Jobs\\SendEmail', "\x00\xff{\"to\":\"a@b\"}", ['traceparent' => '00-x-y-01']),
);

Assert::same(
    'jobs.push',
    $push['calls'][0]['payload']['name'] ?? null,
    'the queue is reached through the jobs namespace',
);
Assert::same(
    'emails',
    $push['calls'][0]['payload']['params']['pipeline'] ?? null,
    'and the pipeline goes in the params rather than in the method name, unlike a KV storage',
);
Assert::same(
    'App\\Jobs\\SendEmail',
    $push['calls'][0]['payload']['params']['task'] ?? null,
    'the task name reaches the wire as written',
);
Assert::same(
    "\x00\xff{\"to\":\"a@b\"}",
    base64_decode($push['calls'][0]['payload']['params']['payload'] ?? '', true),
    'and the payload is the caller\u2019s bytes, unaltered by the encoding layer',
);
Assert::same(
    '00-x-y-01',
    $push['calls'][0]['payload']['params']['headers']['traceparent'] ?? null,
    'headers travel as strings, which is what the server stores',
);
Assert::same('abc-0', $push['result'], 'push answers with the id the server assigned');

// An empty header map has to reach the wire as an *object*, because `json_encode([])` is `[]` and the
// server would refuse a list where it expects a map of headers.
$noHeaders = run_jobs(
    [['result' => ['pushed' => true, 'id' => 'abc-1']]],
    static fn ($jobs) => $jobs->push('App\\Jobs\\Ping'),
);
Assert::true(
    str_contains($noHeaders['calls'][0]['raw'], '"headers":{}'),
    'an empty header map reaches the wire as a JSON object rather than as an empty array, because the server refuses a list there: '
        . $noHeaders['calls'][0]['raw'],
);
Assert::same(
    '',
    base64_decode($noHeaders['calls'][0]['payload']['params']['payload'] ?? 'x', true),
    'and an omitted payload is an empty payload rather than an absent parameter',
);

// `jobs.pipelines` takes no arguments, and PHP cannot produce an empty object — `json_encode([])` is `[]`.
// A no-argument call therefore has to be made without a params argument at all, which is what `ask()`
// does. This was a real bug: the server refused `"params": []` and *every* no-argument method was
// uncallable from PHP.
$pipelines = run_jobs([['result' => ['pipelines' => [['name' => 'emails', 'driver' => 'memory']]]]], static fn ($jobs) => $jobs->pipelines());
Assert::same(
    'jobs.pipelines',
    $pipelines['calls'][0]['payload']['name'] ?? null,
    'the no-argument method is callable at all, which it was not before the server accepted an empty params',
);
Assert::same(
    [],
    $pipelines['calls'][0]['payload']['params'],
    'and it is sent with no params rather than with an empty array, because PHP cannot express an empty object',
);
Assert::same(
    [['name' => 'emails', 'driver' => 'memory']],
    $pipelines['result'],
    'the answer is the list of pipelines with their drivers',
);

$size = run_jobs(
    [['result' => ['size' => 3, 'in_flight' => 1]]],
    static fn ($jobs) => $jobs->size(),
);
Assert::same(['size' => 3, 'in_flight' => 1], $size['result'], 'size answers both numbers as one structure');
Assert::same(
    'jobs.size',
    $size['calls'][0]['payload']['name'] ?? null,
    'and it is reached through the jobs namespace',
);
Assert::same(
    'emails',
    $size['calls'][0]['payload']['params']['pipeline'] ?? null,
    'and the pipeline is named in the params, as every jobs method does',
);

// A partial batch throws, and the exception says where it stopped and what made it — because a batch is
// not atomic and a caller that read "false" would retry the whole thing and duplicate the pushes that
// succeeded.
$partial = run_jobs(
    [['result' => ['pushed' => 2, 'ids' => ['a-0', 'a-1'], 'failed_index' => 2, 'error' => 'no pipeline named "nosuch"']]],
    static function ($jobs) {
        try {
            $jobs->pushBatch([['task' => 'A'], ['task' => 'B'], ['task' => 'C']]);
        } catch (\Volucer\ProtocolException $e) {
            return $e->getMessage();
        }

        return null;
    },
)['result'];
Assert::true(
    is_string($partial) && str_contains($partial, 'index 2'),
    'a partial batch names the index that failed: ' . var_export($partial, true),
);
Assert::true(
    str_contains((string) $partial, 'a-0, a-1'),
    'and the ids that were accepted, so the caller retries the tail rather than the whole batch: ' . var_export($partial, true),
);
Assert::true(
    str_contains((string) $partial, 'nosuch'),
    'and the server\u2019s reason: ' . var_export($partial, true),
);

// A batch that succeeds answers every id, and one that is empty does not call the server at all.
$batch = run_jobs(
    [['result' => ['pushed' => 2, 'ids' => ['b-0', 'b-1'], 'failed_index' => null]]],
    static fn ($jobs) => $jobs->pushBatch([
        ['task' => 'A', 'payload' => 'one'],
        ['task' => 'B'],
    ]),
);
Assert::same(['b-0', 'b-1'], $batch['result'], 'a complete batch answers every id');
Assert::same(
    'one',
    base64_decode($batch['calls'][0]['payload']['params']['jobs'][0]['payload'] ?? '', true),
    'and each job in it carries its own payload',
);
Assert::true(
    str_contains($batch['calls'][0]['raw'], '"headers":{}'),
    'with an empty header map for the job that has none: ' . $batch['calls'][0]['raw'],
);

$empty = run_jobs([], static fn ($jobs) => $jobs->pushBatch([]));
Assert::same([], $empty['result'], 'an empty batch answers with nothing');
Assert::same([], $empty['calls'], 'and makes no call at all, because there is nothing to ask');

// A local mistake — a bad header type — is refused here rather than sent, since a round trip to learn
// that is a round trip wasted.
$badHeader = run_jobs([], static function ($jobs) {
    try {
        $jobs->push('t', '', ['attempts' => 3]);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
})['result'];
Assert::true(
    is_string($badHeader) && str_contains($badHeader, 'string'),
    'a non-string header is refused by the client, naming the rule',
);

$noTask = run_jobs([], static function ($jobs) {
    try {
        $jobs->push('');
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
})['result'];
Assert::true(
    is_string($noTask) && str_contains($noTask, 'task'),
    'and so is a job with no task name',
);

// A refused push raises. The distinction matters here more than anywhere: a queue that silently dropped
// work would be discovered by a customer, not by a log line.
$refusedPush = (function () {
    $fixture = kv_fixture([]);
    $fixture[2] = [MessageType::RETURN, 0xFFFFFFFF, [
        'ok' => false,
        'error' => 'there is no pipeline named "nosuch"; this deployment has "emails"',
    ]];

    $in = server_stream($fixture);
    $out = sink();
    $err = sink();
    $worker = new Worker($in, $out, $err);
    $message = null;
    $worker->run(static function ($request, Worker $worker) use (&$message) {
        try {
            $worker->queue('nosuch')->push('App\\Jobs\\X');
        } catch (\Volucer\ProtocolException $e) {
            $message = $e->getMessage();
        }

        return Response::text('ok');
    });

    return $message;
})();
Assert::true(
    is_string($refusedPush) && str_contains($refusedPush, 'emails'),
    'a refused push raises with the server\u2019s message, which names the pipelines that exist: ' . var_export($refusedPush, true),
);

// An empty pipeline name is refused by the client, since the server would refuse it anyway.
$noPipeline = null;
try {
    $probe = new Worker(server_stream([]), sink(), sink());
    $probe->queue('');
} catch (\InvalidArgumentException $e) {
    $noPipeline = $e->getMessage();
}
Assert::true(
    is_string($noPipeline) && str_contains($noPipeline, 'pipeline'),
    'an empty pipeline name is refused by the client, naming the argument',
);

// ------------------------------------------------------------------------------------------------
// Queues: the consumer side. A `JOB` frame is built by hand and a worker is run in consumer mode over
// in-memory streams, so the assertions are about the two things an application depends on and cannot
// discover later: what the handler was handed, and what the worker answered on its behalf.

/**
 * One `JOB` frame, as the server sends it, with an optional body.
 *
 * `body_len` is derived from the bytes given rather than passed, because a fixture that could disagree
 * with itself would test the disagreement rather than the consumer.
 *
 * @param array<string, mixed> $overrides fields merged over the defaults, so a test can change one
 *                                      without restating the rest — and `headers` is *replaced* rather
 *                                      than merged, which is what lets a case send none at all
 * @return list<array{0: int, 1: int, 2?: mixed, 3?: int}>
 */
function a_job(int $id = 1, string $body = '', array $overrides = []): array
{
    $head = [
        'pipeline' => 'emails',
        'id' => 'a1-0',
        'task' => 'App\\Jobs\\SendEmail',
        'headers' => [['traceparent', '00-abc-def-01'], ['retry-delay', '30']],
        'attempts' => 1,
        'body_len' => strlen($body),
    ];
    foreach ($overrides as $key => $value) {
        $head[$key] = $value;
    }

    $frames = [[MessageType::JOB, $id, $head]];

    if ($body !== '') {
        $frames[] = [MessageType::BODY, $id, $body, Header::FLAG_LAST];
    }

    return $frames;
}

/**
 * Run a worker in consumer mode over in-memory streams.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
 * @param callable(\Volucer\Job, Worker): mixed $handler
 * @return array{frames: list<array{header: Header, payload: string}>, raw: string, stderr: string}
 */
function run_consumer(array $frames, callable $handler): array
{
    $in = server_stream(array_merge([A_HELLO], $frames));
    $out = sink();
    $err = sink();

    (new Worker($in, $out, $err))->consume($handler);

    $raw = drained($out);

    return [
        'frames' => parse_frames($raw),
        'raw' => $raw,
        'stderr' => drained($err),
    ];
}

/** The single ACK a consumer writes, decoded. */
function the_ack(array $run): array
{
    $acks = json_of($run['frames'], MessageType::ACK);
    Assert::same(1, count($acks), 'a consumer answers each job with exactly one ACK');

    return $acks[0];
}

// The job reaches the handler whole: every field the server sent, and the payload as bytes.
$seen = null;
$acknowledged = run_consumer(a_job(1, '{"to":"a@b"}'), static function ($job, $worker) use (&$seen) {
    $seen = $job;

    return null;
});
$ack = the_ack($acknowledged);
Assert::same('ack', $ack['outcome'], 'returning null acknowledges the job');
Assert::same('emails', $seen->pipeline, 'the job knows which pipeline it came from');
Assert::same('a1-0', $seen->id, 'the job carries the driver id the producer was told');
Assert::same('App\\Jobs\\SendEmail', $seen->task, 'and the application task name');
Assert::same(1, $seen->attempts, 'the first reservation reports as attempt one');
Assert::same('{"to":"a@b"}', $seen->payload, 'the payload arrives as the exact bytes that went in');
Assert::same(
    ['to' => 'a@b'],
    $seen->json(),
    'and Job::json() decodes it, because that is the common case and not the contract',
);
Assert::same(
    '00-abc-def-01',
    $seen->header('TraceParent'),
    'headers are looked up case-insensitively, because a producer writes traceparent and an application reads TraceParent',
);
Assert::same('00-abc-def-01', $seen->traceParent(), 'traceparent has a reader of its own');
Assert::same(
    '00-abc-def-01',
    $seen->header('traceparent'),
    'and the traceparent header the producer wrote is readable from the job',
);
Assert::true($ack['duration_us'] >= 0, 'the ACK reports how long the handler took');
Assert::true($ack['peak_memory_bytes'] > 0, 'and the peak memory, so a pool can recycle on it');
Assert::same(true, $ack['keep_alive'], 'a handler that does not ask to be recycled reports keep_alive');

// The three outcomes, one at a time, because each is a different thing the *server* does.
Assert::same(
    'requeue',
    the_ack(run_consumer(a_job(), static fn () => false))['outcome'],
    'returning false requeues: the job is not done and should be handed out again',
);
$rejected = the_ack(run_consumer(
    a_job(),
    static fn () => 'the address is not a mailbox',
));
Assert::same(
    'reject',
    $rejected['outcome'],
    'returning a string rejects: the job is not done and must not come back',
);
Assert::same(
    'the address is not a mailbox',
    $rejected['reason'],
    'and the string becomes the reason the server logs',
);

// A handler that throws: requeued rather than acknowledged, because a consumer that crashed must not
// lose the job — and the exception's message becomes the reason.
$thrown = run_consumer(a_job(), static function () {
    throw new RuntimeException('the database was unreachable');
});
$thrownAck = the_ack($thrown);
Assert::same(
    'requeue',
    $thrownAck['outcome'],
    'a throwing handler requeues rather than discarding the job',
);
Assert::same(
    'the database was unreachable',
    $thrownAck['reason'],
    'and the exception message is what the server logs',
);
Assert::true(
    str_contains($thrown['stderr'], 'the database was unreachable'),
    'the throw is also reported on stderr, where a consumer\u2019s diagnostics go: ' . $thrown['stderr'],
);

// `recycle()` works in consumer mode too, and it is the only way an application asks to be replaced.
$recycled = the_ack(run_consumer(a_job(), static function ($job, $worker) {
    $worker->recycle();

    return null;
}));
Assert::same(false, $recycled['keep_alive'], 'recycle() reports keep_alive false in the ACK');

// Output is *not* a response body in consumer mode, but it must not reach the protocol channel
// unframed either: an unbuffered write to stdout would land inside a frame and desynchronise the
// stream. So it is captured and forwarded as a LOG, which the server renders to its own error log.
$echoed = run_consumer(a_job(), static function () {
    echo 'this is a debug ';
    print 'line';

    return null;
});
// Every byte stdout received is accounted for by a frame. A `print` that escaped the buffer would show
// up here as bytes that belong to no frame, which is the failure this asserts against — not the text's
// absence, because the text is *supposed* to be in a LOG payload.
$framed = 0;
foreach ($echoed['frames'] as $frame) {
    $framed += Header::HEADER_LEN + $frame['header']->payloadLen;
}
Assert::same(
    strlen($echoed['raw']),
    $framed,
    'stdout carries frames and nothing else, which is the rule the whole protocol rests on',
);
$escaped = array_filter(
    $echoed['frames'],
    static fn (array $frame): bool => $frame['header']->messageType !== MessageType::LOG
        && str_contains($frame['payload'], 'a debug line'),
);
Assert::same([], $escaped, 'and the captured text appears in no frame but a LOG');
$forwarded = json_of($echoed['frames'], MessageType::LOG);
Assert::same(1, count($forwarded), 'it is forwarded as a LOG rather than swallowed');
Assert::same(
    'this is a debug line',
    $forwarded[0]['message'] ?? null,
    'with the text the application printed',
);

// A `JOB` with no body sends no BODY frame, so a reader that insisted on one would hang. Exactly one
// ACK, and nothing else — the frames are filtered by type because the handshake's `HELLO_ACK` is an
// output frame too, and a test that counted all of them would be counting the handshake.
$empty = run_consumer(a_job(7, ''), static fn () => null);
$emptyAcks = json_of($empty['frames'], MessageType::ACK);
Assert::same(1, count($emptyAcks), 'a job with no body is answered with the ACK alone');
Assert::same(
    7,
    $empty['frames'][array_key_last($empty['frames'])]['header']->requestId,
    'and the ACK carries the job’s own correlation id',
);
Assert::same(2, count($empty['frames']), 'the whole output is the handshake and the answer');

// `attempts` is the retry policy, so it has to arrive as a number an application can compare.
$attempts = null;
run_consumer(
    a_job(1, '', ['attempts' => 4]),
    static function ($job) use (&$attempts) {
        $attempts = $job->attempts;

        return 'gave up';
    },
);
Assert::same(4, $attempts, 'the attempt count is what the driver recorded, not a local guess');

// A job with no headers at all is normal, not an error.
$withoutHeaders = null;
run_consumer(
    a_job(1, '', ['headers' => []]),
    static function ($job) use (&$withoutHeaders) {
        $withoutHeaders = [$job->headers, $job->header('anything'), $job->json()];

        return null;
    },
);
Assert::same([[], null, null], $withoutHeaders, 'a job with no headers and no payload is handled');

// A malformed header pair is dropped rather than failing every job in the pipeline over one bad key.
$malformed = null;
run_consumer(
    a_job(1, '', ['headers' => [['good', 'yes'], ['missing-value'], ['not-a-list'], [3, 4], ['list', ['a']]]]),
    static function ($job) use (&$malformed) {
        $malformed = $job->headers;

        return null;
    },
);
Assert::same(
    ['good' => 'yes', 3 => '4'],
    $malformed,
    'a pair that is not two scalars is dropped, and a non-string one is cast rather than discarded',
);

// A worker started as a *request* worker that is handed a job: the job must be answered, not dropped,
// because a dropped job leaves the server's poller waiting for a deadline that then kills the worker.
$wrongMode = run_worker([A_HELLO, ...a_job(3)], static fn () => Response::text('unused'));
$wrongAck = json_of($wrongMode['frames'], MessageType::ACK);
Assert::same(1, count($wrongAck), 'a request worker still answers a JOB, so the poller is not left waiting');
Assert::same(
    'reject',
    $wrongAck[0]['outcome'],
    'rejected rather than requeued: the configuration is wrong, and retrying would be a loop',
);
Assert::true(
    str_contains($wrongMode['stderr'], 'wrong mode'),
    'and it says why on stderr: ' . $wrongMode['stderr'],
);

// The mirror of that: a `REQUEST` on a consumer has nowhere to go, and is named rather than guessed at.
$requestOnConsumer = run_consumer(a_get('/', 9), static fn () => null);
Assert::true(
    str_contains($requestOnConsumer['stderr'], 'wrong mode'),
    'a REQUEST on a consumer is reported as the configuration mistake it is: ' . $requestOnConsumer['stderr'],
);
Assert::same(
    0,
    count(json_of($requestOnConsumer['frames'], MessageType::RESPONSE)),
    'and is not answered with a response, which the server would reject as a protocol error',
);
$refusedRequest = json_of($requestOnConsumer['frames'], MessageType::ERROR);
Assert::same(
    1,
    count($refusedRequest),
    'it is answered with an ERROR, because a silent consumer leaves the server waiting for a deadline '
    . 'that then kills the worker over a configuration mistake',
);
Assert::same(
    9,
    $requestOnConsumer['frames'][array_key_last($requestOnConsumer['frames'])]['header']->requestId,
    'correlated to the request it is refusing',
);

// A call made from inside a job handler works, which is what stops a consumer needing a cache from
// deadlocking until its lease runs out.
$callFromJob = null;
$withCall = run_consumer(
    [
        // The job first, then the answer to the call it makes — the order the worker reads them in.
        ...a_job(2),
        // A call id counting *down* from the top, which is the direction the worker owns — the same
        // fixture `run_kv` uses for a call made during a request. A KV value is opaque bytes on the wire
        // and the client's default serializer is JSON, so the fixture stores `"cached"` *as JSON* inside
        // the base64 — which is the encoding the client will look for, not a choice this test makes.
        [MessageType::RETURN, 0xFFFFFFFF, [
            'ok' => true,
            'result' => ['value' => base64_encode(json_encode('cached', JSON_THROW_ON_ERROR))],
        ]],
    ],
    static function ($job, $worker) use (&$callFromJob) {
        $callFromJob = $worker->kv('sessions')->get('key');

        return null;
    },
);
Assert::same('cached', $callFromJob, 'a call made while handling a job is answered');
Assert::same(
    1,
    count(json_of($withCall['frames'], MessageType::CALL)),
    'and the call travels on the same channel, during the job',
);

// A clean EOF ends the consumer loop, exactly as it ends the request loop.
$ended = run_consumer([], static fn () => null);
Assert::same(
    1,
    count($ended['frames']),
    'a clean EOF ends the consume loop after the handshake and no answer',
);
Assert::same(MessageType::HELLO_ACK, $ended['frames'][0]['header']->messageType, 'which is just the handshake');

exit(Assert::report('worker behaviour'));
