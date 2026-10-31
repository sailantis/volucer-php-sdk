<?php

declare(strict_types=1);

/**
 * The multipart fixtures, and what a real `php-cgi` makes of them.
 *
 * This file is the specification the SDK's parser is measured against, rather than the RFC.
 * PHP's own parser has behaviours an application can see — the transposed `$_FILES` shape, the
 * `full_path` key, `UPLOAD_ERR_NO_FILE` for a blank file input — and all of them differ from a
 * straightforward reading of RFC 7578. So rather than encode my understanding of PHP into
 * assertions, each fixture is fed to a `php-cgi` binary and the answer is compared.
 *
 * `MultipartTest.php` imports `multipart_fixtures()` and runs each payload through both.
 * Keeping the payloads here means the oracle path and the parser path cannot drift apart.
 */

/**
 * The payloads to compare, each with a name that says what behaviour it pins down.
 *
 * @return array<string, array{contentType: string, body: string, note: string, diverges?: string}>
 */
function multipart_fixtures(): array
{
    $boundary = 'X';

    $part = static function (string $headers, string $body) use ($boundary): string {
        return '--' . $boundary . "\r\n" . $headers . "\r\n\r\n" . $body . "\r\n";
    };
    $close = '--' . $boundary . "--\r\n";

    return [
        'a plain text field' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="greeting"', 'hello') . $close,
            'note' => 'the smallest possible form, so a failure here is not about nesting',
        ],
        'a file part' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part(
                'Content-Disposition: form-data; name="doc"; filename="x.txt"' . "\r\n"
                . 'Content-Type: text/plain',
                'HELLO',
            ) . $close,
            'note' => 'the CRLF before the delimiter belongs to the delimiter, not the content: 5 bytes',
        ],
        'a file part with no Content-Type' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="blob"; filename="raw.bin"', 'AB') . $close,
            'note' => 'PHP reports type as an empty string rather than guessing',
        ],
        'bracketed field names in $_POST' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="a[b]"', '1')
                . $part('Content-Disposition: form-data; name="a[c]"', '2')
                . $part('Content-Disposition: form-data; name="d[]"', '3')
                . $part('Content-Disposition: form-data; name="d[]"', '4')
                . $close,
            'note' => 'nesting and numeric append, the part parse_str already gets right',
        ],
        'a bracketed file field, which $_FILES transposes' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="a[b]"; filename="y.txt"', 'BYE') . $close,
            'note' => 'becomes $_FILES[a][name][b], NOT $_FILES[a][b][name]',
        ],
        'a repeated file field' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="f[]"; filename="z.txt"', 'Z')
                . $part('Content-Disposition: form-data; name="f[]"; filename="w.txt"', 'WW')
                . $close,
            'note' => '$_FILES[f][name][0] and [1], with every other leaf key index-aligned',
        ],
        'a blank file input' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="blank"; filename=""', '') . $close,
            'note' => 'a browser submits this when the user chose no file: UPLOAD_ERR_NO_FILE, no temp file',
        ],
        'a filename containing a semicolon and a quote' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part(
                'Content-Disposition: form-data; name="q"; filename="a b;c \\" d.txt"',
                'Q',
            ) . $close,
            'note' => 'the quoted-string escape rule, which a query-string parser splits wrongly',
        ],
        'an unquoted filename' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="u"; filename=plain.txt', 'U') . $close,
            'note' => 'RFC 7578 allows an unquoted value, and browsers still send them',
        ],
        'a boundary-looking sequence inside a file' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part(
                'Content-Disposition: form-data; name="tricky"; filename="t.bin"',
                "before\r\n--Xnot-a-boundary\r\nafter",
            ) . $close,
            'note' => 'the classic truncation bug: a delimiter must be followed by -- or a line ending',
            // php-cgi truncates the file here, at 6 bytes of 32. RFC 2046 says the delimiter
            // must be followed by `--` or a line ending, and PHP does not check. This is the one
            // fixture where the parser deliberately does not follow PHP: silently losing a
            // file's tail is the worst outcome available, and no client produces this shape.
            'diverges' => 'php-cgi truncates at the boundary-looking sequence; this parser keeps all 32 bytes',
        ],
        'a file whose content ends with a CRLF' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="t"; filename="t.txt"', "line\r\n") . $close,
            'note' => 'the delimiter consumes one CRLF and must not consume the content\'s own',
        ],
        'preamble and epilogue' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => "this preamble is discarded\r\n--X\r\n"
                . 'Content-Disposition: form-data; name="p"' . "\r\n\r\n" . 'v' . "\r\n"
                . "--X--\r\nthis epilogue is discarded too\r\n",
            'note' => 'PHP discards both, and a parser that does not will misplace every boundary',
        ],
        'a quoted boundary parameter' => [
            'contentType' => 'multipart/form-data; boundary="X"',
            'body' => $part('Content-Disposition: form-data; name="p"', 'v') . $close,
            'note' => 'the boundary may be a quoted string, which is not the same as a literal one',
        ],
        'an empty body with only the closing delimiter' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => "--X--\r\n",
            'note' => 'no parts at all: both superglobals are empty',
        ],
        'a text field whose value starts with --X' => [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => $part('Content-Disposition: form-data; name="v"', "--X\r\n") . $close,
            'note' => 'content starting with --X is not a delimiter: it is preceded by no CRLF',
        ],
    ];
}

/**
 * Ask a real `php-cgi` what it makes of a payload.
 *
 * The CGI environment is the minimum PHP needs to run a script: the method, the content type,
 * the length, and a script to execute. `REDIRECT_STATUS` is what makes `php-cgi` accept a
 * request from outside a server, and leaving it out is the usual reason this silently returns
 * nothing.
 *
 * @return array{post: array<string, mixed>, files: array<string, mixed>}|null
 *         null when no `php-cgi` is available, so the caller can skip rather than fail
 */
function php_cgi_multipart(string $contentType, string $body): ?array
{
    static $cgi = false;
    if ($cgi === false) {
        $cgi = php_cgi_binary();
    }
    if ($cgi === null) {
        return null;
    }

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-multipart-' . bin2hex(random_bytes(6));
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return null;
    }

    try {
        $script = $dir . DIRECTORY_SEPARATOR . 'dump.php';
        file_put_contents($script, '<?php echo json_encode(["post" => $_POST, "files" => $_FILES]);');

        $environment = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) strlen($body),
            'SCRIPT_FILENAME' => $script,
            'SCRIPT_NAME' => '/dump.php',
            'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        // The parent environment is *merged in*, not replaced. On Windows `TEMP` is what
        // `sys_get_temp_dir()` reads, so passing only the CGI variables leaves php-cgi with no
        // temporary directory and every file part arrives as `UPLOAD_ERR_NO_TMP_DIR` — which
        // looks like a parser disagreement rather than the missing environment it is.
        foreach (['PATH', 'TEMP', 'TMP', 'TMPDIR', 'SystemRoot', 'SYSTEMROOT', 'windir', 'HOME'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([$cgi], $descriptors, $pipes, $dir, $environment);
        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (!is_string($stdout)) {
            return null;
        }

        // CGI output is a header block, a blank line, then the body.
        $split = strpos($stdout, "\r\n\r\n");
        $json = $split === false ? $stdout : substr($stdout, $split + 4);

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_key_exists('post', $decoded) || !array_key_exists('files', $decoded)) {
            // A parse failure in php-cgi prints a warning into the body; the message is more
            // useful than a bare null.
            fwrite(STDERR, "php-cgi gave no JSON for a fixture: {$json} {$stderr}\n");

            return null;
        }

        /** @var array<string, mixed> $post */
        $post = $decoded['post'];
        /** @var array<string, mixed> $files */
        $files = $decoded['files'];

        return ['post' => $post, 'files' => $files];
    } finally {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}

/**
 * Find a `php-cgi` next to the running interpreter, or on `PATH`.
 *
 * The suite runs under `php.exe`, so the sibling `php-cgi` is the one whose parser behaviour
 * actually matters — a different build could differ, and comparing against it would be
 * comparing against the wrong PHP.
 */
function php_cgi_binary(): ?string
{
    $candidates = [];

    if (PHP_BINARY !== '' && PHP_BINARY !== 'php' && PHP_BINARY !== 'php.exe') {
        $dir = dirname(PHP_BINARY);
        $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php-cgi.exe';
        $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php-cgi';
    }

    $path = getenv('PATH');
    if (is_string($path)) {
        foreach (explode(PATH_SEPARATOR, $path) as $entry) {
            if ($entry === '') {
                continue;
            }
            $candidates[] = $entry . DIRECTORY_SEPARATOR . 'php-cgi.exe';
            $candidates[] = $entry . DIRECTORY_SEPARATOR . 'php-cgi';
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}
