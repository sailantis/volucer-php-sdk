<?php

declare(strict_types=1);

/**
 * The `[rpc]` call plane, driven without a server process.
 *
 * `stream_socket_pair()` gives this file a real socket for the transport and a script it can drive
 * frame by frame, which is exactly the shape a test of a *client* wants: every interesting case here
 * is about what the client does with a particular answer, or with no answer at all, and none of them
 * need a volucer process. What a socket pair cannot cover is the connect path — that is
 * `rpc_connect()` and `rpc_reconnect()`, which use a real loopback listener.
 *
 * The frame helpers are deliberately written against [`Frame`] and [`Header`] rather than sharing
 * `WorkerTest`'s, because a test that a call *carries the token* has to assert on the bytes, and a
 * helper that went through `Call`-shaped JSON would be asserting on its own assumption.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\CallPlane;
use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\ProtocolException;
use Volucer\Rpc;
use Volucer\Worker;

/**
 * A connected socket pair: `[client, server]`.
 *
 * `STREAM_PF_INET` rather than `STREAM_PF_UNIX`, because the latter does not exist on Windows and
 * this file has to run on the same machine as the rest of the suite. A stream socket is a stream
 * socket; the client cannot tell the difference, which is the point of testing it this way.
 *
 * @return array{0: resource, 1: resource}
 */
function rpc_pair(): array
{
    $pair = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('this platform has no socket pairs, so the transport tests cannot run');
    }

    return $pair;
}

/** Read one frame the client wrote, verifying its checksum. @return array{header: Header, payload: string} */
function rpc_read_frame($stream): array
{
    $head = stream_get_contents($stream, Header::HEADER_LEN);
    if ($head === false || strlen($head) !== Header::HEADER_LEN) {
        throw new RuntimeException('the client wrote no header');
    }

    $header = Header::decode($head);
    $payload = $header->payloadLen === 0 ? '' : stream_get_contents($stream, $header->payloadLen);

    if ($payload === false || strlen($payload) !== $header->payloadLen) {
        throw new RuntimeException('the client wrote a truncated payload');
    }

    if (!$header->declaresNoChecksum() && Frame::crc32c($payload) !== $header->checksum) {
        throw new RuntimeException('the client wrote a frame with a bad checksum');
    }

    return ['header' => $header, 'payload' => $payload];
}

/** Answer a call on the server end of a pair. @param array<string, mixed> $answer */
function rpc_write_return($stream, int $requestId, array $answer): void
{
    $payload = json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    fwrite($stream, (new Frame(MessageType::RETURN, $requestId, $payload))->encode());
    fflush($stream);
}

/** Write a frame of any type, for the tests about frames a caller should never see. */
function rpc_write_frame($stream, int $type, int $requestId, string $payload = ''): void
{
    fwrite($stream, (new Frame($type, $requestId, $payload))->encode());
    fflush($stream);
}

/**
 * A plane bound to one end of a pair, with a short deadline so a test that expects a timeout does not
 * spend ten seconds producing it.
 */
function rpc_plane($client, string $token = 'a-token'): Rpc
{
    return new Rpc($client, $token, '127.0.0.1:1', 0.5);
}

/** Run `$body` with a `$_SERVER` of the given values, restoring it afterwards. */
function rpc_with_server(array $values, callable $body): mixed
{
    $saved = $_SERVER;
    foreach (['VOLUCER_RPC', 'VOLUCER_RPC_TOKEN', 'HTTP_X_VOLUCER_RPC', 'HTTP_X_VOLUCER_RPC_TOKEN'] as $name) {
        unset($_SERVER[$name]);
    }
    $_SERVER = array_merge($_SERVER, $values);

    try {
        return $body();
    } finally {
        $_SERVER = $saved;
    }
}

// ------------------------------------------------------------ the seam itself

// The reason the interface exists: the three storage clients are written against `CallPlane`, and
// both transports have to satisfy it, or the same `Kv` cannot be built over either.
Assert::true(new Worker(fopen('php://memory', 'r+b'), fopen('php://memory', 'w+b'), fopen('php://memory', 'w+b')) instanceof CallPlane, 'a Worker is a call plane');
Assert::true(Rpc::connect() instanceof CallPlane, 'an Rpc is a call plane');

// ------------------------------------------------------- nothing to connect to

// A backend that granted nothing is handed no address. That is an ordinary state — a request which
// never touches a store must still work — so the plane refuses the *call*, with the variable named,
// rather than failing when it is constructed.
$unconfigured = rpc_with_server([], static fn (): Rpc => Rpc::connect());
Assert::same(null, $unconfigured->call('kv.cache.get', ['key' => 'a']), 'a call with no address returns null');
Assert::contains('VOLUCER_RPC', (string) $unconfigured->lastCallError(), 'the refusal names the missing variable');
Assert::contains('services', (string) $unconfigured->lastCallError(), 'and points at the services list');
Assert::same(false, $unconfigured->lastCallWasUnknown(), 'nothing was sent, so the call certainly did not happen');

// An empty or whitespace value is what a shell produces while somebody is debugging, and both must
// read as "nothing was granted" rather than as an address to dial.
Assert::same(
    null,
    rpc_with_server(['VOLUCER_RPC' => '  '], static fn (): ?string => Rpc::connect()->address()),
    'an all-whitespace address reads as absent',
);

// ------------------------------------------------------------ reading the environment

// The token is the half of the grant that travels on every call, so the environment has to be read the
// same way for both halves. Asserted through a live frame rather than a getter alone, because a getter
// that returned one value while a call used another would pass a test of the getter.
$fromCgi = rpc_with_server(
    ['VOLUCER_RPC' => 'unix:/run/volucer/rpc.sock', 'VOLUCER_RPC_TOKEN' => 'token-from-cgi'],
    static fn (): Rpc => Rpc::connect(),
);
Assert::same('unix:/run/volucer/rpc.sock', $fromCgi->address(), 'the CGI parameter is read as the address');

[$client, $server] = rpc_pair();
$plane = new Rpc($client, 'token-from-cgi', 'unix:/run/volucer/rpc.sock', 0.5);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => 'ok']);
$plane->call('kv.cache.get');
Assert::same(
    'token-from-cgi',
    json_decode(rpc_read_frame($server)['payload'], true, 512, JSON_THROW_ON_ERROR)['token'] ?? null,
    'and the token from the same environment travels with the call',
);

// The header form is what an `upstream` backend is handed, and it must be found too — an application
// behind one has no CGI parameters at all.
$headerForm = rpc_with_server(
    ['HTTP_X_VOLUCER_RPC' => '127.0.0.1:6001', 'HTTP_X_VOLUCER_RPC_TOKEN' => 'token-from-header'],
    static fn (): Rpc => Rpc::connect(),
);
Assert::same('127.0.0.1:6001', $headerForm->address(), 'the upstream header form is read as an address');

// A CGI parameter wins over a header of the same meaning, because a FastCGI backend can only ever
// have the former and an upstream only the latter — a request carrying both is a proxy mistake, and
// the value volucer wrote is the one volucer meant.
Assert::same(
    'unix:/from-cgi.sock',
    rpc_with_server(
        [
            'VOLUCER_RPC' => 'unix:/from-cgi.sock',
            'HTTP_X_VOLUCER_RPC' => '127.0.0.1:6001',
        ],
        static fn (): ?string => Rpc::connect()->address(),
    ),
    'the CGI parameter wins over the header',
);

// A process environment variable is the fallback, for a CLI script or a hand-run test that never went
// through the server at all.
putenv('VOLUCER_RPC=127.0.0.1:6002');
Assert::same(
    '127.0.0.1:6002',
    rpc_with_server([], static fn (): ?string => Rpc::connect()->address()),
    'a process environment variable is honoured when no request carries one',
);
putenv('VOLUCER_RPC');

// ---------------------------------------------------------------- one round trip

// The answer is written *before* the call, because a socket pair buffers and the client finds it
// waiting — which is what a fast server looks like from here, and is enough to test the reading side.
// The interleaved case, where the client blocks, is covered by the timeout test below.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => ['value' => 'aGVsbG8=']]);
$answer = $plane->call('kv.cache.get', ['key' => 'YQ==']);

Assert::same(['value' => 'aGVsbG8='], $answer, 'a served call returns its result');
Assert::same(null, $plane->lastCallError(), 'and records no error');

$request = rpc_read_frame($server);
Assert::same(MessageType::CALL, $request['header']->messageType, 'the client writes a CALL');
Assert::same(
    ['name' => 'kv.cache.get', 'params' => ['key' => 'YQ=='], 'token' => 'a-token'],
    json_decode($request['payload'], true, 512, JSON_THROW_ON_ERROR),
    'the call carries its name, its params and the token',
);
Assert::same(0, $request['header']->flags & Header::FLAG_NO_CHECKSUM, 'an [rpc] frame is always checksummed');

// The id counts down from the top of the range, so it can never look like a request's id to a reader
// that decides by which half of the range the id falls in.
Assert::true($request['header']->requestId >= 0x80000000, 'a call id is in the upper half of the range');

[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => 'first']);
$plane->call('kv.cache.get');
$first = rpc_read_frame($server);
rpc_write_return($server, 0xFFFFFFFE, ['ok' => true, 'result' => 'second']);
$plane->call('kv.cache.get');
$second = rpc_read_frame($server);
Assert::same(0xFFFFFFFF - 1, $second['header']->requestId, 'the next call takes the next id down');
Assert::true($second['header']->requestId < $first['header']->requestId, 'and never repeats one');

// ------------------------------------------------------- a refusal is not a failure of the wire

[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, [
    'ok' => false,
    'error' => 'call kv.cache.get carried a token no backend of this deployment was issued',
]);
Assert::same(null, $plane->call('kv.cache.get'), 'a refused call returns null');
Assert::contains('no backend of this deployment', (string) $plane->lastCallError(), 'and the reason is recorded');
Assert::same(false, $plane->lastCallWasUnknown(), 'a definite refusal is safe to retry');

// The "may have happened" case, which is the whole reason `unknown` exists. A caller that could not
// tell this from the refusal above would retry a write that had already landed.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => false, 'error' => 'the lock state is unknown', 'unknown' => true]);
Assert::same(null, $plane->call('lock.acquire'), 'an undecided call returns null');
Assert::same(true, $plane->lastCallWasUnknown(), 'and says the call may have taken effect');

// A `null` result that *succeeded* is the third case, and the one a cache miss produces: it must not
// leave an error behind, or every miss would read as a failure.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => null]);
Assert::same(null, $plane->call('kv.cache.get'), 'a served null result is null');
Assert::same(null, $plane->lastCallError(), 'and is distinguishable from a refusal');

// A plane with no token omits the field rather than sending an empty string, because the server tells
// "carried no token" from "carried a token no backend was issued" and the second message would send an
// operator looking at the wrong thing.
[$client, $server] = rpc_pair();
$plane = new Rpc($client, '', '127.0.0.1:1', 0.5);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => 'ok']);
$plane->call('kv.cache.get');
$request = json_decode(rpc_read_frame($server)['payload'], true, 512, JSON_THROW_ON_ERROR);
Assert::same(false, array_key_exists('token', $request), 'an empty token is omitted, not sent as ""');

// -------------------------------------------------- frames a caller should never see

// The listener answers CALL and PING and refuses everything else; a RETURN is the only frame it sends.
// Anything else means the two ends disagree about what is on this socket, and the framing can no
// longer be trusted — so the connection goes with the exception.
foreach (
    [
        'a request' => [MessageType::REQUEST, 1, '{"method":"GET"}'],
        'a log record' => [MessageType::LOG, 0, '{"message":"hi"}'],
        'a ping' => [MessageType::PING, 0, ''],
    ] as $what => [$type, $id, $payload]
) {
    [$client, $server] = rpc_pair();
    $plane = rpc_plane($client);
    rpc_write_frame($server, $type, $id, $payload);
    Assert::throws(
        static fn () => $plane->call('kv.cache.get'),
        "a {$what} from the server is refused rather than read as an answer",
        'carries only RETURN frames',
    );
}

// An answer to a different call. Sequential calls make this impossible on a correct connection, and
// if it happens the two id spaces have been mixed up — which is worth an exception, not a guess.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0x11111111, ['ok' => true, 'result' => 'someone else\'s answer']);
Assert::throws(
    static fn () => $plane->call('kv.cache.get'),
    'an answer on another call\'s id is refused',
    'expected the answer to',
);

// A payload that is not JSON, and a RETURN whose payload is a JSON *scalar*: both are a server this
// client does not understand, and neither may be read as "no result".
foreach (['not JSON at all' => ['nonsense', 'not valid JSON'], 'a JSON scalar' => ['"a string"', 'not a JSON object']] as $what => [$payload, $expected]) {
    [$client, $server] = rpc_pair();
    $plane = rpc_plane($client);
    rpc_write_frame($server, MessageType::RETURN, 0xFFFFFFFF, $payload);
    Assert::throws(
        static fn () => $plane->call('kv.cache.get'),
        "an answer that is {$what} is refused",
        $expected,
    );
}

// A declared length past what this client will read is refused *before* reading, so a corrupt header
// cannot make a CGI child allocate gigabytes.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
$header = new Header(0x7FFFFFFF, MessageType::RETURN, 0, 0xFFFFFFFF, 0);
fwrite($server, pack('VCCvVV', $header->payloadLen, Header::PROTOCOL_VERSION, $header->messageType, 0, $header->requestId, 0));
fflush($server);
Assert::throws(
    static fn () => $plane->call('kv.cache.get'),
    'an absurd declared length is refused before it is allocated',
    'past the',
);

// --------------------------------------------------------------------- the deadline

// The case a worker's channel deliberately does not have: a socket that has stopped answering. In a
// CGI process a blocking read with no deadline is a request that hangs until `max_execution_time`
// kills it — and that failure names the wrong process.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
$started = microtime(true);
Assert::throws(
    static fn () => $plane->call('kv.cache.get'),
    'a call with no answer raises rather than hanging',
    'got no answer within',
);
$elapsed = microtime(true) - $started;
Assert::true($elapsed >= 0.4 && $elapsed < 5.0, sprintf('the deadline is the configured one (waited %.2f s)', $elapsed));
Assert::same(true, $plane->lastCallWasUnknown(), 'an unanswered call may still have been served');
Assert::contains('timeout', (string) $plane->lastCallError(), 'the message says which knob raises it');

// A deadline of zero is refused at construction, for the reason `[rpc].call_timeout_seconds = 0` is:
// `Option` already means "uncapped", so a zero is a value the setting cannot express.
Assert::throws(
    static fn () => new Rpc(null, 'token', '127.0.0.1:1', 0.0),
    'a zero deadline is refused',
    'positive',
);

// ------------------------------------------------------------- the storage clients, over rpc

// The point of the seam: `Kv` does not know which transport it has, and the call it makes is the one a
// worker's `Kv` makes. Asserted on the bytes, because that is the claim.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => ['stored' => true]]);
Assert::same(true, $plane->kv('cache')->set('greeting', 'hello'), 'Kv::set over rpc reports success');
$request = json_decode(rpc_read_frame($server)['payload'], true, 512, JSON_THROW_ON_ERROR);
Assert::same('kv.cache.set', $request['name'], 'the method is kv.<storage>.<verb>, exactly as over a channel');
Assert::same(
    base64_encode('greeting'),
    $request['params']['key'] ?? null,
    'the key is base64, exactly as over a channel',
);
Assert::same(base64_encode('"hello"'), $request['params']['value'] ?? null, 'and so is the JSON-encoded value');

// A refusal reaching `Kv` is an exception rather than a `null` that reads as a cache miss — the
// behaviour the class documentation promises, which has to hold on this transport too.
[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => false, 'error' => 'no kv storage named "cache" is granted']);
Assert::throws(
    static fn () => $plane->kv('cache')->get('greeting'),
    'a refused Kv call raises rather than returning a miss',
    'kv.cache.get failed',
);

// And with no deployment grant at all, the exception names the deployment rather than the client.
Assert::throws(
    static fn () => rpc_with_server([], static fn (): Rpc => Rpc::connect())->kv('cache')->set('a', 'b'),
    'a Kv built on an unconfigured plane explains the deployment',
    'VOLUCER_RPC',
);

[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => ['id' => 'the-id', 'acquired' => true]]);
Assert::same('the-id', $plane->lock()->acquire('pdf:create'), 'Lock over rpc returns the id that holds the lock');
Assert::same('lock.acquire', json_decode(rpc_read_frame($server)['payload'], true, 512, JSON_THROW_ON_ERROR)['name'], 'and calls lock.acquire');

[$client, $server] = rpc_pair();
$plane = rpc_plane($client);
rpc_write_return($server, 0xFFFFFFFF, ['ok' => true, 'result' => ['id' => 'job-1']]);
$plane->queue('mail')->push('{"to":"a@b"}');
Assert::same('jobs.push', json_decode(rpc_read_frame($server)['payload'], true, 512, JSON_THROW_ON_ERROR)['name'], 'Jobs over rpc calls jobs.push');

// ---------------------------------------------------------------- a real loopback connection

/**
 * A client in its own process, so a server in this one can answer it.
 *
 * A socket pair cannot cover what this section is about — that a plane *connects* to the address it
 * was told, that a connection the server closed is noticed and reported as undecided, and that the
 * **next** call dials again rather than staying broken for the life of the process — because all
 * three need two processes: the caller blocks in `awaitReturn()` while the server has to accept and
 * answer. So the client is a subprocess, which is also closer to what a `php-cgi` child is.
 *
 * The three calls are the three states of a connection: answered, broken under the caller's feet, and
 * dialled afresh. The middle one is the reason there is no liveness probe: a plane that asked "is
 * this socket still good?" before every call would pay a round trip to save one, and would still be
 * able to lose the connection between the answer to the probe and the call.
 */
const RPC_CLIENT_FIXTURE = <<<'PHP'
<?php

require $argv[1];

use Volucer\Rpc;

$plane = new Rpc(null, $argv[3], $argv[2], (float) $argv[4]);
$report = [];

foreach ([1, 2, 3] as $index) {
    try {
        $report[] = [
            'call' => $index,
            'result' => $plane->call('kv.cache.get', ['key' => 'YQ==']),
            'error' => $plane->lastCallError(),
            'unknown' => $plane->lastCallWasUnknown(),
        ];
    } catch (Throwable $thrown) {
        $report[] = [
            'call' => $index,
            'threw' => $thrown->getMessage(),
            'unknown' => $plane->lastCallWasUnknown(),
        ];
    }
}

echo json_encode($report, JSON_THROW_ON_ERROR);
PHP;

/** A temporary directory, removed on the way out. */
function rpc_dir(): string
{
    $path = sys_get_temp_dir() . '/volucer-rpc-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($path, 0700, true);

    return $path;
}

$dir = rpc_dir();
$script = $dir . '/client.php';
file_put_contents($script, RPC_CLIENT_FIXTURE);

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($listener === false) {
    throw new RuntimeException("could not bind a loopback listener: {$errstr} ({$errno})");
}
$address = (string) stream_socket_get_name($listener, false);

// An *array* command, so `proc_open` execs PHP directly rather than through a shell: a shell would
// be one more process to wait for and one more thing whose exit status is not the client's.
$pipes = [];
$process = proc_open(
    [PHP_BINARY, $script, __DIR__ . '/../autoload.php', $address, 'token-from-test', '2.0'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);

if (!is_resource($process)) {
    throw new RuntimeException('could not start the client fixture');
}

/**
 * Accept one connection and answer the frame on it, or close it unanswered.
 *
 * @param array<string, mixed>|null $answer null closes without answering, which is the
 *                                          connection-dies-mid-call case
 */
$serve_one = static function ($listener, ?array $answer): void {
    $connection = @stream_socket_accept($listener, 10);
    if ($connection === false) {
        throw new RuntimeException('the client never connected');
    }

    $frame = rpc_read_frame($connection);
    if ($answer === null) {
        // No answer: the peer closes while the call is outstanding, so the caller cannot know whether
        // it was served. This is `unknown`, not a refusal.
        fclose($connection);

        return;
    }

    rpc_write_return($connection, $frame['header']->requestId, $answer);
    // Read nothing more and close: the next call in the fixture has to dial again, which is what a
    // server closing an idle `[rpc]` connection looks like from the client's side.
    fclose($connection);
};

// Two accepts for three calls, and that is the whole point rather than an off-by-one. The server
// answers the first connection and closes it; the client's *second* call therefore cannot be answered
// — whether it notices on the write or on the read, it raises with `unknown` set — and its third call
// dials again, which is the accept below. A plane that remembered the breakage would never make that
// third call.
$serve_one($listener, ['ok' => true, 'result' => 'first']);
$serve_one($listener, ['ok' => true, 'result' => 'reconnected']);

fclose($listener);

$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
$status = proc_close($process);

if ($status !== 0) {
    throw new RuntimeException("the client fixture failed ({$status}):\n{$stderr}");
}

$report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

Assert::same('first', $report[0]['result'] ?? null, 'a plane connects on its first call and is answered');
Assert::same(true, array_key_exists('error', $report[0]), 'the fixture reports at least one answered call');
Assert::same(null, $report[0]['error'], 'the first call records no error');

// The second call is on a connection the server has closed. Whether the client notices on the write
// or on the read depends on how far the FIN had travelled, so the assertion is on the *classification*
// rather than on which of the two messages it produced — that is the part a caller branches on.
Assert::true(isset($report[1]['threw']), 'a call on a connection the server closed raises');
Assert::same(true, $report[1]['unknown'] ?? false, 'and says the call may have taken effect');

Assert::same(
    'reconnected',
    $report[2]['result'] ?? null,
    'the call after a broken connection dials again rather than failing for the rest of the process',
);
Assert::same(true, array_key_exists('error', $report[2]), 'and is an answered call rather than a throw');
Assert::same(null, $report[2]['error'], 'which records no error, so the breakage did not outlive it');

// A call to an address nothing is listening on is a *definite* non-event — nothing was sent — and it
// must not be reported as an undecided one, which is what would teach a caller to distrust the flag.
$plane = new Rpc(null, 'token', '127.0.0.1:1', 1.0);
Assert::same(null, $plane->call('kv.cache.get'), 'a plane whose server is down returns null');
Assert::contains('could not be reached', (string) $plane->lastCallError(), 'and says so');
Assert::same(false, $plane->lastCallWasUnknown(), 'nothing was sent, so the call did not happen');

foreach (glob($dir . '/*') ?: [] as $entry) {
    @unlink($entry);
}
@rmdir($dir);

// ------------------------------------------------------------------------ the unix prefix

Assert::same('/run/volucer/rpc.sock', Rpc::unixPath('unix:/run/volucer/rpc.sock'), 'unix: is stripped to the path');
Assert::same(null, Rpc::unixPath('127.0.0.1:6001'), 'a TCP address has no path');
Assert::same(
    Worker::unixPath('unix:/tmp/x.sock'),
    Rpc::unixPath('unix:/tmp/x.sock'),
    'the two transports read the prefix the same way, which is why there is one implementation',
);

exit(Assert::report('rpc call plane'));
