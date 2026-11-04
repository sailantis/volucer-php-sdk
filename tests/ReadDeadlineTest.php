<?php

declare(strict_types=1);

/**
 * A deadline on an idle read must not end a worker.
 *
 * PHP applies `default_socket_timeout` to reads on a **socket** stream, and
 * `stream_get_contents()` returns `''` both for "the deadline passed" and for "the peer closed".
 * Reading the first as the second is how every attached worker in a pool exits at once after a
 * quiet minute: the SDK's `readHeader()` returned `null`, `run()` returned, and the process
 * ended — silently, because a clean EOF is the documented way for the server to stop a worker.
 *
 * Two things make this different from everything in `WorkerTest.php`:
 *
 *   - it runs over a real **socket**, because the bug cannot exist on `php://memory` — a memory
 *     stream never times out, so a test over one passes against the broken code;
 *   - the worker runs in a **separate process**, because `run()` blocks until the connection ends
 *     and somebody has to behave as the server while it does. A subprocess rather than `fork()`
 *     because `pcntl` is not available on Windows and this suite has to run there.
 *
 * The child reports by **writing a file**, never over a pipe. A PHP subprocess that inherits a
 * pipe nobody drains blocks in `fwrite`, and a harness that blocks cannot tell a hung worker from
 * a hung test — which is exactly the confusion this suite is meant to remove.
 *
 *     php tests/ReadDeadlineTest.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\Response;
use Volucer\Worker;

if (($argv[1] ?? '') === '--child') {
    read_deadline_child($argv[2], (string) $argv[3], (string) $argv[4]);
    exit(0);
}

/**
 * The worker, in its own process: connect, serve, report, exit.
 *
 * The deadline is set **before** the stream is created, because `default_socket_timeout` is
 * captured at that moment. Setting it afterwards is silently ineffective, which is the mistake
 * that made the original probe measure nothing at all.
 */
function read_deadline_child(string $address, string $deadline, string $report): void
{
    ini_set('default_socket_timeout', $deadline);

    $connection = stream_socket_client("tcp://$address", $errno, $errstr, 10.0);
    if ($connection === false) {
        file_put_contents($report, json_encode(['outcome' => "connect failed: $errstr", 'calls' => 0]));
        return;
    }

    $calls = 0;

    try {
        // The connection is **both** halves: the protocol is one bidirectional socket, exactly as
        // `Worker::create($connection, $connection)` sets it up. Passing a memory stream as the
        // output would answer the handshake into a buffer nobody reads, which looks like a worker
        // that connected and then went silent.
        $memory = fopen('php://memory', 'r+b');
        (new Worker($connection, $connection, $memory))->run(
            static function () use (&$calls): Response {
                $calls++;
                return Response::text('ok');
            },
        );
        $outcome = 'returned';
    } catch (\Throwable $error) {
        $outcome = $error::class . ': ' . $error->getMessage();
    }

    file_put_contents($report, json_encode(['outcome' => $outcome, 'calls' => $calls]));
}

/**
 * Start the worker subprocess and complete the handshake.
 *
 * @return array{0: resource, 1: resource, 2: string} server side, process, the report path
 */
function read_deadline_spawn(string $deadline): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    Assert::true($listener !== false, "listen on an ephemeral port: $errstr");

    $address = stream_socket_get_name($listener, false);
    Assert::true(is_string($address) && $address !== '', 'the listener reports its address');

    $dir = sys_get_temp_dir() . '/volucer-read-deadline';
    @mkdir($dir);
    $report = $dir . '/' . bin2hex(random_bytes(8)) . '.json';
    @unlink($report);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $dir . '/out.log', 'a'],
        2 => ['file', $dir . '/err.log', 'a'],
    ];

    $process = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=E_ALL', __FILE__, '--child', $address, $deadline, $report],
        $descriptors,
        $pipes,
    );
    Assert::true(is_resource($process), 'start the worker subprocess');

    $server = stream_socket_accept($listener, 15.0);
    Assert::true($server !== false, 'the worker connected');
    @fclose($listener);

    // The handshake has to complete before `run()` reaches its loop, so the idle period under
    // test is the request loop's rather than the handshake's.
    fwrite($server, (new Frame(MessageType::HELLO, 0, ''))->encode());
    read_deadline_expect($server, MessageType::HELLO_ACK);

    return [$server, $process, $report];
}

/**
 * Read frames until one of `$type` arrives, or the peer closes.
 *
 * The reader's deadline is set explicitly: this stream is created with the suite's own default,
 * and a frame reader that does two reads per frame must not be at the mercy of it.
 *
 * @param resource $stream
 */
function read_deadline_expect($stream, int $type, float $within = 10.0): bool
{
    stream_set_timeout($stream, 1);
    $deadline = microtime(true) + $within;

    while (microtime(true) < $deadline) {
        $head = stream_get_contents($stream, Header::HEADER_LEN);

        if ($head === false || $head === '') {
            if (read_deadline_quiet($stream)) {
                continue;
            }

            return false;   // the peer closed
        }

        if (strlen($head) !== Header::HEADER_LEN) {
            return false;
        }

        $header = Header::decode($head);
        $payload = '';

        while (strlen($payload) < $header->payloadLen) {
            $piece = stream_get_contents($stream, $header->payloadLen - strlen($payload));
            if ($piece === false || $piece === '') {
                if (read_deadline_quiet($stream)) {
                    continue;
                }

                return false;
            }
            $payload .= $piece;
        }

        if ((int) $header->messageType === $type) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a short read means "still waiting" rather than "closed".
 *
 * The same predicate the SDK fix uses, deliberately written twice: this file must be able to fail
 * against a `Worker` that gets it wrong, so it cannot share the implementation.
 *
 * @param resource $stream
 */
function read_deadline_quiet($stream): bool
{
    if (feof($stream)) {
        return false;
    }

    $meta = @stream_get_meta_data($stream);

    return is_array($meta) && ($meta['timed_out'] ?? false) === true;
}

function read_deadline_request(int $id = 1): string
{
    return (new Frame(MessageType::REQUEST, $id, json_encode([
        'method' => 'GET',
        'uri' => '/',
        'protocol' => 'HTTP/1.1',
        'headers' => [['Accept', 'text/html'], ['Host', 'example.test']],
        'remote_addr' => '127.0.0.1',
        'script_filename' => 'index.php',
        'body_len' => 0,
        'https' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))->encode();
}

/**
 * Write part of a frame, wait past the reader's deadline, then write the rest.
 *
 * This is what a *slow* server looks like from the worker's side, as opposed to a quiet one.
 * The gap has to exceed the deadline or the reader simply gets both halves in one read and the
 * split is never exercised.
 *
 * @param resource $stream
 */
function read_deadline_send_split($stream, string $bytes, int $first, int $gapMicros = 2_500_000): void
{
    fwrite($stream, substr($bytes, 0, $first));
    fflush($stream);
    usleep($gapMicros);
    fwrite($stream, substr($bytes, $first));
    fflush($stream);
}

/**
 * Close the connection, wait for the worker, and read what it reported.
 *
 * @param resource $server
 * @param resource $process
 * @return array{calls: int, outcome: string, elapsed: float}
 */
function read_deadline_finish($server, $process, string $report): array
{
    $started = microtime(true);

    // Let the worker finish whatever it is writing before the socket goes away. Closing the
    // instant the RESPONSE frame lands is a race the worker loses by throwing "the server closed
    // its input while a frame was being written" — a real failure mode, but not the one under
    // test, and it made these scenarios flaky rather than wrong. The clock starts after the
    // pause so the promptness assertion in scenario 2 still measures the close, not this.
    usleep(300_000);

    // The close is what a healthy worker must react to, so it is always the last event.
    @fclose($server);

    $deadline = microtime(true) + 10.0;
    $status = proc_get_status($process);

    while ($status['running'] && microtime(true) < $deadline) {
        usleep(10_000);
        $status = proc_get_status($process);
    }

    $elapsed = microtime(true) - $started;
    proc_close($process);

    $calls = 0;
    $outcome = 'the worker never returned, even after the connection closed';

    if (is_file($report)) {
        $decoded = json_decode((string) file_get_contents($report), true);
        if (is_array($decoded)) {
            $calls = (int) ($decoded['calls'] ?? 0);
            $outcome = (string) ($decoded['outcome'] ?? $outcome);
        }
        @unlink($report);
    }

    return ['calls' => $calls, 'outcome' => $outcome, 'elapsed' => $elapsed];
}

/** A one second deadline, so the suite is quick; production's default is sixty. */
const RD_DEADLINE = '1';

// ---------------------------------------------------------------------------------------------
// 1. An idle connection does not end a worker.
//
// The production scenario: handshake answered, then the server says nothing for longer than the
// worker's read deadline. The assertion has to be *liveness during the idle period*, not the
// outcome — a worker that reads the timeout as EOF returns from `run()` cleanly, so its outcome
// is "returned" either way and asserting on that alone passes against the broken code.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn(RD_DEADLINE);
usleep(3_500_000);   // 3.5x the deadline: three chances to mistake a timeout for EOF

$alive = proc_get_status($process)['running'];
Assert::true(
    $alive,
    'a worker is still alive after idling past its read deadline (the broken code exits here)',
);

$idle = read_deadline_finish($server, $process, $report);
Assert::same('returned', $idle['outcome'], 'and it returns once the connection really closes');

// ---------------------------------------------------------------------------------------------
// 2. A real close still ends a worker, and promptly.
//
// The other half of the same distinction, and the half that must not regress: a pool that cannot
// be shut down is a worse bug than one that shuts down too early.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn(RD_DEADLINE);
$closed = read_deadline_finish($server, $process, $report);

Assert::same('returned', $closed['outcome'], 'a closed connection ends the worker');
Assert::true(
    $closed['elapsed'] < 6.0,
    sprintf('a closed connection ends the worker promptly (took %.1fs)', $closed['elapsed']),
);

// ---------------------------------------------------------------------------------------------
// 3. A request that arrives *after* an idle gap is still served.
//
// The assertion the bug fails in production: the worker is not merely alive, it is still in its
// read loop and answers the first request that comes after the quiet period.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn(RD_DEADLINE);
usleep(2_500_000);   // idle past the deadline

fwrite($server, read_deadline_request());

$served = read_deadline_expect($server, MessageType::RESPONSE);
$afterIdle = read_deadline_finish($server, $process, $report);

Assert::true($served, 'the worker answers a request that arrives after an idle gap');
Assert::same(1, $afterIdle['calls'], 'and the handler ran exactly once');

// ---------------------------------------------------------------------------------------------
// 4. A deployment with no deadline at all behaves the same way.
//
// `default_socket_timeout = -1` is the other supported answer, and it must not be *worse*: with
// no deadline a read blocks until data or a close, which is exactly what the fix gives a
// deadline'd worker.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn('-1');
usleep(2_000_000);
$noDeadline = read_deadline_finish($server, $process, $report);

Assert::same('returned', $noDeadline['outcome'], 'with no deadline, a close still ends the worker');

// ---------------------------------------------------------------------------------------------
// 5. A header split across the deadline is reassembled, not rejected.
//
// The loud half of the same bug. `stream_get_contents($h, 16)` returns whatever has arrived, so a
// *slow* server delivers 8 bytes, the deadline passes, and the remaining 8 follow. `readHeader()`
// used to hand the first 8 to `Header::decode`, which throws `ProtocolException` on "a frame
// header is exactly 16 bytes, got 8" — and a `ProtocolException` out of `run()` kills the worker.
//
// So a server that is merely slow at the wrong moment loses a worker just as surely as one that
// goes quiet, which is why this is the same defect and not a separate one.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn(RD_DEADLINE);
read_deadline_send_split($server, read_deadline_request(), Header::HEADER_LEN / 2);

$served = read_deadline_expect($server, MessageType::RESPONSE);
$split = read_deadline_finish($server, $process, $report);

Assert::true($served, 'a header split across the deadline is still read as a frame');
Assert::same(1, $split['calls'], 'and the handler ran exactly once');
Assert::same('returned', $split['outcome'], 'and the worker was never killed by a malformed frame');

// ---------------------------------------------------------------------------------------------
// 6. A payload split across the deadline is reassembled, not decoded as a truncated frame.
//
// The same shape one layer down, and it is what `readExactly()` exists for. A payload that
// arrived in two pieces used to be reported as "a frame declared N payload bytes but the stream
// ended after M", which is again a `ProtocolException` and therefore a dead worker — for a slow
// server rather than a closed one.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn(RD_DEADLINE);

$frame = read_deadline_request(2);
read_deadline_send_split($server, $frame, Header::HEADER_LEN);   // whole header, then stall

$served = read_deadline_expect($server, MessageType::RESPONSE);
$splitPayload = read_deadline_finish($server, $process, $report);

Assert::true($served, 'a payload split across the deadline is still read as a frame');
Assert::same(1, $splitPayload['calls'], 'and the handler ran exactly once');
Assert::same('returned', $splitPayload['outcome'], 'and the worker was never killed by a short read');

// ---------------------------------------------------------------------------------------------
// 7. A close *inside* a header is still fatal, and says so.
//
// Reassembling across a deadline must not turn a genuine mid-frame truncation into an idle
// worker: a stream that really stops between the two halves is a protocol failure, and a worker
// that silently kept waiting for bytes that will never come would hang the pool instead.
// ---------------------------------------------------------------------------------------------

[$server, $process, $report] = read_deadline_spawn(RD_DEADLINE);
fwrite($server, substr(read_deadline_request(3), 0, Header::HEADER_LEN / 2));
fflush($server);
usleep(200_000);
$truncated = read_deadline_finish($server, $process, $report);

Assert::true(
    str_contains($truncated['outcome'], 'ProtocolException'),
    sprintf('a close inside a header is a protocol failure, not a shutdown (got: %s)', $truncated['outcome']),
);

exit(Assert::report('an idle read deadline does not end a worker'));
