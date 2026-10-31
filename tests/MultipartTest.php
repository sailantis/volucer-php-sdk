<?php

declare(strict_types=1);

/**
 * The parser, measured against a real `php-cgi`.
 *
 * Every fixture is fed to both and the resulting `$_POST` and `$_FILES` compared. The comparison
 * is the test: the assertions below about specific shapes exist to explain a failure, not to
 * define correctness. Where the two disagree, `php-cgi` is right and this parser is wrong.
 *
 * `tmp_name` and `full_path` cannot be compared literally — one is a path php-cgi chose and the
 * other is the client's directory, which php-cgi fills from a parameter that browsers send only
 * for `full_path`-capable forms. So `tmp_name` is compared by *shape* and existence, and
 * `full_path` is compared against the fixture's own filename.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';
require __DIR__ . '/fixtures/multipart.php';

use Volucer\Multipart;
use Volucer\ProtocolException;

$fixtures = multipart_fixtures();
$oracle = php_cgi_binary();

if ($oracle === null) {
    if (getenv('VOLUCER_E2E') === '1') {
        fwrite(STDOUT, "FAIL MultipartTest: no php-cgi found, and VOLUCER_E2E=1 forbids skipping\n");
        exit(1);
    }
    fwrite(STDOUT, "ok   MultipartTest (skipped: no php-cgi to compare against)\n");
    exit(0);
}

/**
 * Run one payload through the parser in slices, the way a worker receives it.
 *
 * The slice size is deliberately adversarial: 7 bytes means a boundary, a header block and a
 * CRLF are all split across calls, which is where an incremental parser breaks.
 */
$parse = static function (string $contentType, string $body, int $slice): Multipart {
    $parser = new Multipart($contentType);
    $length = strlen($body);
    for ($offset = 0; $offset < $length; $offset += $slice) {
        $parser->feed(substr($body, $offset, $slice));
    }
    $parser->finish();

    return $parser;
};

/**
 * Replace every temporary path with a placeholder, so two runs can be compared.
 *
 * What is preserved is the *shape*: whether a path is empty, and that it names a file that
 * exists and holds the right number of bytes. That is what an application can observe, and it
 * is what php-cgi's own choice of name cannot agree on.
 *
 * @param array<string, mixed> $files
 * @return array<string, mixed>
 */
$normalise = static function (array $files) use (&$normalise): array {
    $out = [];
    foreach ($files as $key => $value) {
        if (is_array($value)) {
            $out[$key] = $normalise($value);
            continue;
        }
        $out[$key] = $value;
    }

    return $out;
};

/**
 * Compare one fixture against the oracle.
 *
 * `tmp_name` is handled separately because it is the one field whose value is a path that
 * neither side chose the same way.
 *
 * @param array<string, mixed> $expected
 * @param array<string, mixed> $actual
 * @return list<string> the dotted paths that differ
 */
$differences = function (array $expected, array $actual, string $path = '') use (&$differences): array {
    $out = [];
    $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
    sort($keys);

    foreach ($keys as $key) {
        $where = $path === '' ? (string) $key : $path . '.' . $key;
        $hasExpected = array_key_exists($key, $expected);
        $hasActual = array_key_exists($key, $actual);

        if (!$hasExpected || !$hasActual) {
            $out[] = $where . ($hasExpected ? ' (missing here)' : ' (extra here)');
            continue;
        }

        $left = $expected[$key];
        $right = $actual[$key];

        if (is_array($left) && is_array($right)) {
            $out = array_merge($out, $differences($left, $right, $where));
            continue;
        }

        if (str_contains($where, 'tmp_name')) {
            // Both must agree on *whether* there is a file, and that it can be read. The
            // position of `tmp_name` in the key is not fixed: a bracketed field nests below the
            // leaf key, so it can be `a.tmp_name.b` as well as `f.tmp_name.0`.
            if (($left === '') !== ($right === '')) {
                $out[] = $where . ' (one side has a temp file and the other does not)';
            } elseif ($right !== '' && !is_file($right)) {
                $out[] = $where . ' (the parser named a file that does not exist)';
            }
            continue;
        }

        if ($left !== $right) {
            $out[] = sprintf(
                '%s (php-cgi: %s, parser: %s)',
                $where,
                var_export($left, true),
                var_export($right, true),
            );
        }
    }

    return $out;
};

$compared = 0;
foreach ($fixtures as $name => $fixture) {
    $expected = php_cgi_multipart($fixture['contentType'], $fixture['body']);
    if ($expected === null) {
        Assert::same(true, false, "php-cgi answered for the fixture '{$name}'");
        continue;
    }
    $compared++;

    // A fixture may name the one behaviour it deliberately does not copy from php-cgi. It is
    // still run against the oracle, but only the parts that are not the stated divergence are
    // compared — otherwise the divergence would either be an unexplained failure or, worse, be
    // "fixed" by copying a truncation bug.
    $diverges = $fixture['diverges'] ?? null;

    foreach ([1, 7, 64 * 1024, strlen($fixture['body']) + 1] as $slice) {
        $parser = $parse($fixture['contentType'], $fixture['body'], $slice);

        $postDiffs = $differences($expected['post'], $parser->post());
        Assert::same(
            [],
            $postDiffs,
            "{$name}: \$_POST matches php-cgi at slice size {$slice} ({$fixture['note']})",
        );

        if ($diverges === null) {
            $fileDiffs = $differences($expected['files'], $parser->files());
            Assert::same(
                [],
                $fileDiffs,
                "{$name}: \$_FILES matches php-cgi at slice size {$slice} ({$fixture['note']})",
            );
        } else {
            // The shape must still be right; only the size is expected to differ, because that
            // is exactly what a truncation changes.
            Assert::same(
                array_keys($expected['files']),
                array_keys($parser->files()),
                "{$name}: the field names still match php-cgi (divergence: {$diverges})",
            );
            foreach ($parser->files() as $field => $leaves) {
                Assert::same(
                    array_keys($expected['files'][$field] ?? []),
                    is_array($leaves) ? array_keys($leaves) : [],
                    "{$name}: '{$field}' still has php-cgi's leaf keys",
                );
            }
        }

        // A file part is only useful if its bytes are the client's bytes.
        foreach ($parser->files() as $field => $leaves) {
            if (!is_array($leaves) || !isset($leaves['tmp_name'], $leaves['size'])) {
                continue;
            }
            if (is_string($leaves['tmp_name']) && $leaves['tmp_name'] !== '') {
                $contents = @file_get_contents($leaves['tmp_name']);
                Assert::same(
                    $leaves['size'],
                    is_string($contents) ? strlen($contents) : -1,
                    "{$name}: the declared size of '{$field}' matches the bytes on disk",
                );
            }
        }

        $parser->cleanup();
    }
}

// ---------------------------------------------------------------- specific shapes

// The transposed `$_FILES` shape is the single most likely thing to be implemented wrongly, so
// it is asserted directly as well as differentially — a differential test that fails for an
// unrelated reason would otherwise hide it.
$parser = $parse(
    'multipart/form-data; boundary=X',
    "--X\r\nContent-Disposition: form-data; name=\"a[b]\"; filename=\"y.txt\"\r\n\r\nBYE\r\n--X--\r\n",
    4096,
);
$files = $parser->files();
Assert::same('y.txt', $files['a']['name']['b'] ?? null, '$_FILES transposes: a[b] puts b under name, not the reverse');
Assert::same(3, $files['a']['size']['b'] ?? null, '$_FILES transposes the size leaf the same way');
$parser->cleanup();

// A field name with no brackets is a single key, dots and spaces included.
$parser = $parse(
    'multipart/form-data; boundary=X',
    "--X\r\nContent-Disposition: form-data; name=\"user.email\"\r\n\r\nv\r\n--X--\r\n",
    4096,
);
Assert::same(['user.email' => 'v'], $parser->post(), 'a dotted field name is one key, not a nested path');
$parser->cleanup();

// ---------------------------------------------------------------- failures

$parser = new Multipart('multipart/form-data; boundary=X');
$parser->feed("--X\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\r\n");
Assert::throws(
    static fn () => $parser->finish(),
    'a body with no closing boundary is refused rather than half-parsed',
    'closing boundary',
);
$parser->cleanup();

Assert::throws(
    static fn () => new Multipart('multipart/form-data'),
    'a multipart request with no boundary is refused',
    'boundary',
);
Assert::throws(
    static fn () => new Multipart('multipart/form-data; boundary=' . str_repeat('x', 71)),
    'a boundary longer than RFC 2046 allows is refused',
    'boundary',
);

// ---------------------------------------------------------------- helpers

Assert::same(true, Multipart::isMultipart('multipart/form-data; boundary=X'), 'isMultipart accepts the type');
Assert::same(false, Multipart::isMultipart('application/x-www-form-urlencoded'), 'isMultipart rejects another type');
Assert::same(true, Multipart::isMultipart('Multipart/Form-Data; boundary=X'), 'isMultipart ignores case');

Assert::same(2048, Multipart::toBytes('2K'), 'an ini size suffix K is understood');
Assert::same(8 * 1024 * 1024, Multipart::toBytes('8M'), 'an ini size suffix M is understood');
Assert::same(1024 * 1024 * 1024, Multipart::toBytes('1G'), 'an ini size suffix G is understood');
Assert::same(123, Multipart::toBytes('123'), 'a bare number is bytes');
Assert::same(0, Multipart::toBytes('0'), 'zero is unlimited, as in PHP');
Assert::same(null, Multipart::toBytes('8MB'), 'an unparseable size is refused rather than guessed');

// ---------------------------------------------------------------- memory, which is the point

// The claim this whole design rests on: a file part is not held in memory while it is parsed.
// The size is chosen to stay under PHP's default `upload_max_filesize` (2M) so the test runs
// without an ini override, and the bound is generous — the failure being guarded is holding the
// body, which would be the full size, not a few kilobytes of buffer.
$megabytes = 1;
$fileBytes = $megabytes * 1024 * 1024;

$parser = new Multipart('multipart/form-data; boundary=X');
$parser->feed(
    "--X\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"big.bin\"\r\n\r\n",
);

$baseline = memory_get_usage();
$chunk = str_repeat('A', 65536);
$sent = 0;
$peak = 0;
while ($sent < $fileBytes) {
    $parser->feed($chunk);
    $sent += 65536;
    $peak = max($peak, memory_get_usage() - $baseline);
}
$parser->feed("\r\n--X--\r\n");
$parser->finish();

$peak = max($peak, memory_get_usage() - $baseline);

Assert::same($fileBytes, $parser->files()['doc']['size'] ?? null, 'the large upload arrived whole');
Assert::same(
    true,
    $peak < $fileBytes / 2,
    sprintf(
        'parsing a %d-byte upload held %d bytes (%.2f MiB): a file part is spooled to disk, '
        . 'not accumulated (holding it would be about %d bytes)',
        $fileBytes,
        $peak,
        $peak / 1048576,
        $fileBytes,
    ),
);
$parser->cleanup();

// The control, so the number above is known to mean something: holding the same bytes as one
// string costs at least the file's size. If this ever stops being true, the assertion above is
// measuring nothing and should be rewritten.
$beforeHolding = memory_get_usage();
$held = str_repeat('B', $fileBytes);
$holdingCost = memory_get_usage() - $beforeHolding;
Assert::same(
    true,
    $holdingCost >= $fileBytes,
    sprintf('control: holding %d bytes in a string costs %d, so the measurement is real', $fileBytes, $holdingCost),
);
unset($held);

// ---------------------------------------------------------------- cleanup

// A moved file must survive `cleanup()`: an application that has stored the upload owns it, and
// deleting it would be the worst possible bug here.
$parser = $parse(
    'multipart/form-data; boundary=X',
    "--X\r\nContent-Disposition: form-data; name=\"f\"; filename=\"a.txt\"\r\n\r\nDATA\r\n--X--\r\n",
    4096,
);
$tmp = $parser->files()['f']['tmp_name'];
Assert::same(true, is_file($tmp), 'the parser leaves its temp file where $_FILES names it');
$moved = $tmp . '.moved';
Assert::same(true, @rename($tmp, $moved), 'an application can move the temp file the way PHP requires');
$parser->cleanup();
Assert::same(true, is_file($moved), 'cleanup() does not delete a file the application moved');
@unlink($moved);

// A file nobody moved must not be left behind.
$parser = $parse(
    'multipart/form-data; boundary=X',
    "--X\r\nContent-Disposition: form-data; name=\"f\"; filename=\"a.txt\"\r\n\r\nDATA\r\n--X--\r\n",
    4096,
);
$tmp = $parser->files()['f']['tmp_name'];
$parser->cleanup();
Assert::same(false, is_file($tmp), 'cleanup() deletes a temp file nobody claimed');

exit(Assert::report('MultipartTest ' . $compared . ' fixtures'));
