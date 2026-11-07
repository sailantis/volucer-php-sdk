<?php

declare(strict_types=1);

/**
 * Uploads as the worker sees them: superglobals, `$request->files()`, and the cleanup.
 *
 * The parser itself is verified against a real `php-cgi` in `MultipartTest.php`. What this file
 * covers is everything *around* the parser, and the one failure that would be a security bug
 * rather than a wrong answer: a superglobal left populated after the request, so the next
 * request's user sees this one's upload.
 *
 * Every test here drives a real `Worker` over in-memory streams, so the frames are the ones a
 * server would actually send.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\Multipart;
use Volucer\Request;
use Volucer\Response;
use Volucer\Uploads;
use Volucer\Worker;

/**
 * Encode `[type, request_id, payload, flags]` frames into a stream at its start.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
 * @return resource
 */
function upload_server_stream(array $frames)
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

/**
 * A REQUEST frame carrying a multipart body, split into BODY frames the way the server sends it.
 *
 * `$chunk` deliberately defaults to something that does not divide the body evenly, so a
 * boundary is split across two frames. A parser that only works when each frame happens to end
 * on a boundary is the bug this guards against.
 *
 * @return list<array{0: int, 1: int, 2?: mixed, 3?: int}>
 */
function a_upload(
    string $body,
    int $id = 1,
    int $chunk = 37,
    array $headers = [],
    string $method = 'POST',
    string $uri = '/upload',
): array {
    $contentType = 'multipart/form-data; boundary=X';
    $allHeaders = array_merge([
        ['Content-Type', $contentType],
        ['Host', 'example.test'],
    ], $headers);

    $frames = [[MessageType::REQUEST, $id, [
        'method' => $method,
        'uri' => $uri,
        'protocol' => 'HTTP/1.1',
        'headers' => $allHeaders,
        'remote_addr' => '127.0.0.1',
        'script_filename' => 'index.php',
        'body_len' => strlen($body),
        'https' => false,
    ]]];

    $length = strlen($body);
    for ($offset = 0; $offset < $length; $offset += $chunk) {
        $slice = substr($body, $offset, $chunk);
        $last = $offset + $chunk >= $length ? Header::FLAG_LAST : 0;
        $frames[] = [MessageType::BODY, $id, $slice, $last];
    }

    return $frames;
}

/** A two-part multipart body: one text field and one file. */
function a_form_with_one_file(string $boundary = 'X'): string
{
    return "--{$boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nA title\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n"
        . "Content-Type: text/plain\r\n\r\nFILEBYTES\r\n"
        . "--{$boundary}--\r\n";
}

/** @return resource */
function upload_sink()
{
    return fopen('php://memory', 'r+b');
}

/** @param resource $stream */
function upload_drained($stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}

/**
 * Run a worker over the given frames and collect what it wrote.
 *
 * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
 * @return array{frames: list<array>, raw: string, stderr: string}
 */
function run_upload_worker(array $frames, callable $handler): array
{
    $in = upload_server_stream(array_merge([[MessageType::HELLO, 0]], $frames));
    $out = upload_sink();
    $err = upload_sink();

    (new Worker($in, $out, $err))->run($handler);

    return ['raw' => upload_drained($out), 'stderr' => upload_drained($err)];
}

// ---------------------------------------------------------------- the request an application sees

$seen = null;
$run = run_upload_worker(
    a_upload(a_form_with_one_file()),
    static function (Request $request) use (&$seen): Response {
        $seen = [
            'post' => $_POST,
            'files' => $_FILES,
            'request_post' => $request->post(),
            'request_files' => $request->files(),
            'server_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'server_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'get' => $_GET,
            'cookie' => $_COOKIE,
            'body_len' => strlen($request->body),
            'tmp' => $_FILES['doc']['tmp_name'] ?? '',
        ];

        return Response::text('ok');
    },
);

Assert::same(['title' => 'A title'], $seen['post'], '$_POST carries the text field');
Assert::same('a.txt', $seen['files']['doc']['name'] ?? null, '$_FILES carries the file name');
Assert::same(9, $seen['files']['doc']['size'] ?? null, '$_FILES carries the size in bytes');
Assert::same('text/plain', $seen['files']['doc']['type'] ?? null, '$_FILES carries the part content type');
Assert::same($seen['post'], $seen['request_post'], '$request->post() is the same array as $_POST');
Assert::same($seen['files'], $seen['request_files'], '$request->files() is the same array as $_FILES');
Assert::same('POST', $seen['server_method'], '$_SERVER carries REMOTE_METHOD from the frame');
Assert::same(
    'multipart/form-data; boundary=X',
    $seen['server_type'],
    '$_SERVER carries CONTENT_TYPE, which CGI gives an unprefixed name',
);
Assert::same([], $seen['get'], '$_GET is empty when the URI has no query');
Assert::same([], $seen['cookie'], '$_COOKIE is empty when no Cookie header arrived');
Assert::same(
    true,
    $seen['body_len'] > 0,
    '$request->body still carries the raw multipart bytes, unlike php://input under a SAPI',
);

// The file must be readable and hold the client's bytes, byte for byte. Read *inside* the
// handler, because the `finally` that cleans up has already run by the time the worker returns —
// which is the behaviour the leak guard below asserts.
$seenBody = null;
run_upload_worker(
    a_upload(a_form_with_one_file()),
    static function (Request $request) use (&$seenBody): Response {
        $path = $request->files()['doc']['tmp_name'];
        $seenBody = is_string($path) && is_file($path) ? file_get_contents($path) : null;

        return Response::text('ok');
    },
);

Assert::same('FILEBYTES', $seenBody, 'the temp file holds exactly the part body');

// ---------------------------------------------------------------- the leak guard

// The failure this guards: a worker process serves many requests, so a superglobal left
// populated hands the next request's user the previous one's upload. It is the one bug in this
// area that is a security problem rather than a wrong answer.
$afterFirst = null;
$afterSecond = null;
$aSecondHandlerRan = false;

$frames = array_merge(
    a_upload(a_form_with_one_file(), 1),
    [[MessageType::REQUEST, 2, [
        'method' => 'GET',
        'uri' => '/after',
        'protocol' => 'HTTP/1.1',
        'headers' => [['Host', 'example.test']],
        'remote_addr' => '127.0.0.1',
        'script_filename' => 'index.php',
        'body_len' => 0,
        'https' => false,
    ]]],
);

run_upload_worker(
    $frames,
    static function (Request $request) use (&$afterFirst, &$afterSecond, &$aSecondHandlerRan): Response {
        if ($request->uri === '/upload') {
            $afterFirst = [
                'post_before' => $_POST,
                'files_before' => $_FILES,
                'tmp' => $_FILES['doc']['tmp_name'] ?? '',
            ];
        } else {
            $aSecondHandlerRan = true;
            $afterSecond = [
                'post' => $_POST,
                'files' => $_FILES,
                'get' => $_GET,
                'cookie' => $_COOKIE,
                'request_post' => $request->post(),
                'request_files' => $request->files(),
            ];
        }

        return Response::text('ok');
    },
);

Assert::true($aSecondHandlerRan, 'both requests reached the handler');
Assert::same(
    ['title' => 'A title'],
    $afterFirst['post_before'],
    'the first request saw its own $_POST',
);
Assert::same(
    [],
    $afterSecond['post'],
    'a later request sees an empty $_POST: the previous request\'s form data did not leak',
);
Assert::same(
    [],
    $afterSecond['files'],
    'a later request sees an empty $_FILES: the previous request\'s upload did not leak',
);
Assert::same([], $afterSecond['request_post'], '$request->post() is empty for a GET after an upload');
Assert::same([], $afterSecond['request_files'], '$request->files() is empty for a GET after an upload');
Assert::same(false, is_file((string) $afterFirst['tmp']), 'the upload temp file is gone once the request ended');

// ---------------------------------------------------------------- a throwing handler

// The reset has to be in a `finally`, or a handler that throws leaves the previous request's
// globals in place — which is exactly the case least likely to be noticed in development and
// most likely to matter in production.
$leakedAfterThrow = null;
$tmpFromThrow = '';

$frames = array_merge(
    a_upload(a_form_with_one_file(), 1),
    [[MessageType::REQUEST, 2, [
        'method' => 'GET',
        'uri' => '/after',
        'protocol' => 'HTTP/1.1',
        'headers' => [['Host', 'example.test']],
        'remote_addr' => '127.0.0.1',
        'script_filename' => 'index.php',
        'body_len' => 0,
        'https' => false,
    ]]],
);

run_upload_worker(
    $frames,
    static function (Request $request) use (&$leakedAfterThrow, &$tmpFromThrow): Response {
        if ($request->uri === '/upload') {
            $tmpFromThrow = $_FILES['doc']['tmp_name'] ?? '';
            throw new RuntimeException('deliberate');
        }
        $leakedAfterThrow = ['post' => $_POST, 'files' => $_FILES];

        return Response::text('ok');
    },
);

Assert::same([], $leakedAfterThrow['post'] ?? null, 'a handler that threw does not leak $_POST to the next request');
Assert::same([], $leakedAfterThrow['files'] ?? null, 'a handler that threw does not leak $_FILES to the next request');
Assert::same(false, $tmpFromThrow === '' || is_file($tmpFromThrow), 'a handler that threw does not leak its temp file');

// ---------------------------------------------------------------- an application that moves the file

$movedInto = null;
$sourceAfterMove = null;

$destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-moved-' . bin2hex(random_bytes(6));

run_upload_worker(
    a_upload(a_form_with_one_file()),
    static function (Request $request) use (&$movedInto, &$sourceAfterMove, $destination): Response {
        $file = $request->files()['doc'];
        $sourceAfterMove = $file['tmp_name'];
        $movedInto = Uploads::move($file, $destination);

        return Response::text('ok');
    },
);

Assert::same(true, $movedInto, 'Uploads::move() succeeds in worker mode, where move_uploaded_file() cannot');
Assert::same(
    'FILEBYTES',
    is_file($destination) ? file_get_contents($destination) : null,
    'the destination holds the client\'s bytes',
);
Assert::same(
    false,
    is_file($sourceAfterMove),
    'the source is gone after the move, as move_uploaded_file() leaves it',
);
@unlink($destination);

// ---------------------------------------------------------------- a second upload in the same process

// A worker reuses its process, so the second upload must behave exactly as the first. This is
// the "warm process" case that a request-per-process SAPI never exercises.
$sizes = [];
$paths = [];
$frames = array_merge(
    a_upload(a_form_with_one_file(),),
    a_upload(a_form_with_one_file(), 2),
);

run_upload_worker(
    $frames,
    static function (Request $request) use (&$sizes, &$paths): Response {
        $sizes[] = $_FILES['doc']['size'] ?? null;
        $paths[] = $_FILES['doc']['tmp_name'] ?? '';

        return Response::text('ok');
    },
);

Assert::same([9, 9], $sizes, 'two uploads in one process each report their own size');
Assert::true(
    ($paths[0] ?? '') !== ($paths[1] ?? ''),
    'the two uploads got distinct temp files, so neither overwrote the other',
);

// ---------------------------------------------------------------- what is populated, and what is not

$populated = null;

run_upload_worker(
    a_upload(a_form_with_one_file(), 1, 37, [], 'POST', '/upload?title=from-query&page=2'),
    static function (Request $request) use (&$populated): Response {
        $populated = [
            'request' => $_REQUEST,
            'server' => $_SERVER,
            'session_is_array' => isset($_SESSION) && is_array($_SESSION),
        ];

        return Response::text('ok');
    },
);

// `$_REQUEST` merges the form fields over the query string, which is what `php-cgi` does with
// the default `request_order = "GP"` — measured, not assumed: a posted `title` overrides the
// query's, and a key only the query has survives.
Assert::same(
    ['title' => 'A title', 'page' => '2'],
    $populated['request'],
    '$_REQUEST is the form fields merged over the query string, POST last so it wins',
);

Assert::same(
    'POST',
    $populated['server']['REQUEST_METHOD'] ?? null,
    '$_SERVER is populated for every HTTP request, not only a multipart one',
);

Assert::same(
    '/upload?title=from-query&page=2',
    $populated['server']['REQUEST_URI'] ?? null,
    '$_SERVER carries REQUEST_URI, the full request target including the query, as CGI does',
);

Assert::same(
    '/upload',
    $populated['server']['SCRIPT_NAME'] ?? null,
    'SCRIPT_NAME is the request path, not the front controller: php-cgi is handed the controller '
    . 'for every path, so deriving this from script_filename would report /index.php for /wp-admin/',
);

// ---------------------------------------------------------------- the helpers

$sample = ['tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 123];

Assert::same(false, Uploads::isOk(['tmp_name' => '', 'error' => UPLOAD_ERR_OK]), 'isOk rejects an empty tmp_name');
Assert::same(true, Uploads::isOk($sample), 'isOk accepts an entry with a temp name and no error');
Assert::same(
    false,
    Uploads::isOk(['tmp_name' => '/x', 'error' => UPLOAD_ERR_INI_SIZE]),
    'isOk rejects an entry that carries an error',
);
Assert::same(
    false,
    Uploads::move(['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE], '/tmp/nowhere'),
    'move() refuses an entry with no file rather than creating one',
);
Assert::same(
    false,
    Uploads::movedFromClient($sample),
    'movedFromClient() reports the truth: a file this process wrote is not one PHP will accept',
);
Assert::same(
    'The uploaded file exceeds upload_max_filesize',
    Uploads::errorMessage(['error' => UPLOAD_ERR_INI_SIZE]),
    'errorMessage names the ini limit',
);
Assert::same(null, Uploads::errorMessage(['error' => UPLOAD_ERR_OK]), 'errorMessage is null for a healthy entry');
Assert::same(strlen((string) file_get_contents(__FILE__)), Uploads::size($sample), 'size() reads the bytes on disk');

exit(Assert::report('uploads in worker mode'));
