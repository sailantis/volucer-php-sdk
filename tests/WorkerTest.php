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
// Found by type rather than by position: the worker writes its response frames in the order the\n// handler drove them, so an index would be asserting on the fixture's layout rather than on the call.\n = null;\nforeach ($result['frames'] as $frame) {\n    if ($frame['header']->messageType === MessageType::CALL) {\n        $callFrame = $frame['header']->requestId;\n    }\n}\nAssert::same(0xFFFFFFFF, $callFrame, 'the CALL carried the id the server allocated');
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
exit(Assert::report('worker behaviour'));
