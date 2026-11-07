<?php

declare(strict_types=1);

/**
 * A handler that ends the process instead of returning.
 *
 * This cannot be tested in-process, because the thing under test *is* the process ending. So the
 * fixture is a real worker over real pipes in a child process, and the assertions are on the
 * frames it wrote before it went away.
 *
 * Two failures are guarded here, and they are different:
 *
 * 1. **The response is lost.** `exit` ends the process, so the worker dies mid-request, the server
 *    answers the client with a `502` and replaces the worker. The shutdown handler recovers the
 *    captured body and sends a real `RESPONSE`/`BODY`/`END`, so the client gets its page.
 * 2. **stdout is the protocol channel.** PHP flushes output buffers at shutdown, and a shutdown
 *    function the application registered runs then too — WordPress has one for fatal errors. Any
 *    of that reaching stdout lands inside a frame and desynchronises the stream. So the test also
 *    asserts that what a *late* shutdown handler printed is nowhere in the bytes on the wire.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;

const EXIT_FIXTURE = <<<'PHP'
<?php
require $argv[1];

use Volucer\Request;
use Volucer\Response;
use Volucer\Worker;

Worker::create()->run(function (Request $request): Response {
    if ($request->uri === '/exits') {
        // The shape WordPress uses: boot the request, register a shutdown handler (WordPress does
        // this for fatal errors), produce the page, then end the request.
        register_shutdown_function(static function (): void {
            echo 'LATE-OUTPUT-MUST-NOT-REACH-STDOUT';
        });

        echo '<html>page that was produced before exit</html>';

        exit(0);
    }

    if ($request->uri === '/returns') {
        register_shutdown_function(static function (): void {
            echo 'LATE-OUTPUT-MUST-NOT-REACH-STDOUT';
        });

        return Response::text('returned normally');
    }

    return Response::text('unreachable');
});
PHP;

/** Build the frames a server would send for one GET, with no body. */
function exit_a_get(int $id, string $uri): array
{
    return [[MessageType::REQUEST, $id, json_encode([
        'method' => 'GET',
        'uri' => $uri,
        'protocol' => 'HTTP/1.1',
        'headers' => [['Host', 'example.test'], ['Accept', 'text/html']],
        'remote_addr' => '127.0.0.1',
        'script_filename' => '/srv/wp/index.php',
        'body_len' => 0,
        'https' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]];
}

$directory = sys_get_temp_dir() . '/volucer-exit-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);

$fixture = $directory . '/worker.php';
file_put_contents($fixture, EXIT_FIXTURE);

/**
 * Run the fixture once, send it the given requests, and return the bytes it wrote to stdout.
 *
 * One call is one worker process, which is the point: `exit` ends the process, so "the request
 * after the one that exited" is not a thing within a single run. It is a thing across runs, and
 * that is what the second call checks.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
 * @return array{raw: string, stderr: string}
 */
function exit_run(string $fixture, array $frames): array
{
    $process = proc_open(
        [PHP_BINARY, $fixture, __DIR__ . '/../autoload.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('could not start the worker fixture');
    }

    $written = '';
    foreach ($frames as $frame) {
        $written .= (new Frame($frame[0], $frame[1], (string) ($frame[2] ?? ''), $frame[3] ?? 0))->encode();
    }
    fwrite($pipes[0], $written);
    fflush($pipes[0]);

    // The worker ends on the EOF it reads when stdin closes, which is the protocol's own shutdown,
    // so closing stdin is what makes this terminate instead of waiting for a deadline. Stdout is
    // read to the end first: the shutdown handler writes the recovered response *after* the last
    // request, so a reader that stopped at "one response is enough" would miss exactly the frames
    // under test.
    fclose($pipes[0]);

    $raw = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['raw' => $raw, 'stderr' => $stderr];
}

/** Decode a worker's stdout into frames, asserting every byte belonged to one. @return array{0: list<array{header: Header, payload: string}>, 1: int} */
function exit_frames(string $raw): array
{
    $frames = [];
    $offset = 0;
    while ($offset + Header::HEADER_LEN <= strlen($raw)) {
        $header = Header::decode(substr($raw, $offset, Header::HEADER_LEN));
        $offset += Header::HEADER_LEN;
        $payload = substr($raw, $offset, $header->payloadLen);
        $offset += $header->payloadLen;
        $frames[] = ['header' => $header, 'payload' => $payload];
    }

    return [$frames, $offset];
}

// ------------------------------------------------- a request the handler exits

$exited = exit_run($fixture, array_merge(
    [[MessageType::HELLO, 0, '']],
    exit_a_get(1, '/exits'),
));

[$exitFrames, $exitConsumed] = exit_frames($exited['raw']);

$bodies = [];
$heads = [];
$ends = [];
foreach ($exitFrames as $frame) {
    $id = $frame['header']->requestId;
    if ($frame['header']->messageType === MessageType::BODY) {
        $bodies[$id] = ($bodies[$id] ?? '') . $frame['payload'];
    }
    if ($frame['header']->messageType === MessageType::RESPONSE) {
        $heads[$id] = json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
    }
    if ($frame['header']->messageType === MessageType::END) {
        $ends[$id] = json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
    }
}

Assert::true(isset($heads[1]), 'a request the handler ended with exit() still gets a response head');
Assert::same(
    200,
    $heads[1]['status'] ?? null,
    'the recovered response is a 200, because the exit status is not available to a shutdown function',
);
Assert::same(
    '<html>page that was produced before exit</html>',
    $bodies[1] ?? null,
    'the body echoed before exit() is recovered and delivered instead of being lost with the process',
);
Assert::true(
    !str_contains($bodies[1] ?? '', 'LATE-OUTPUT'),
    'a shutdown handler the application registered while serving does not append to the body: '
    . 'PHP runs shutdown functions in registration order, so the responder must be registered earlier',
);
Assert::true(isset($ends[1]), 'the recovered response is terminated with an END frame, so the server does not report a truncation');
Assert::same(0, $ends[1]['app_status'] ?? null, 'the recovered response reports app_status 0');
Assert::same(
    $exitConsumed,
    strlen($exited['raw']),
    'every byte the worker wrote is a complete frame, so the protocol stream is aligned',
);

// --------------------------------------- stdout stays the protocol channel

// The second half of the same failure, and a different one: PHP flushes output buffers at
// shutdown, and a shutdown function the application registered runs then too. Reaching stdout
// would land inside a frame. The recovered body must be the response, and nothing else may be.
Assert::true(
    !str_contains($exited['raw'], 'LATE-OUTPUT-MUST-NOT-REACH-STDOUT'),
    'output from a shutdown function registered by an ordinary request never reaches stdout',
);

// ------------------------------------------------- the next process is unaffected

// `exit` costs the worker, which is the honest consequence and the reason `attach` exists: the
// next process boots again. What must not happen is that the *shutdown handler* changes how an
// ordinary request is answered.
$returned = exit_run($fixture, array_merge(
    [[MessageType::HELLO, 0, '']],
    exit_a_get(1, '/returns'),
));

[$returnFrames, $returnConsumed] = exit_frames($returned['raw']);
$returnBodies = [];
$returnHeads = [];
foreach ($returnFrames as $frame) {
    $id = $frame['header']->requestId;
    if ($frame['header']->messageType === MessageType::BODY) {
        $returnBodies[$id] = ($returnBodies[$id] ?? '') . $frame['payload'];
    }
    if ($frame['header']->messageType === MessageType::RESPONSE) {
        $returnHeads[$id] = json_decode($frame['payload'], true, 512, JSON_THROW_ON_ERROR);
    }
}

Assert::same('returned normally', $returnBodies[1] ?? null, 'a handler that returns is answered as before');
Assert::same(200, $returnHeads[1]['status'] ?? null, 'a returning handler is not treated as an exit');
Assert::same(
    $returnConsumed,
    strlen($returned['raw']),
    'a normal request also leaves the protocol stream aligned, with no trailing output',
);
Assert::true(
    !str_contains($returned['raw'], 'LATE-OUTPUT-MUST-NOT-REACH-STDOUT'),
    'the output trap is in place even when no request is owed, which is when the worker is stopping',
);

// The fixtures are the only thing in the directory.
foreach (glob($directory . '/*') ?: [] as $path) {
    @unlink($path);
}
@rmdir($directory);

exit(Assert::report('a handler that calls exit()'));
