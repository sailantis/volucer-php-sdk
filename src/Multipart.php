<?php

declare(strict_types=1);

namespace Volucer;

/**
 * An incremental `multipart/form-data` parser.
 *
 * ## Why this exists, and why it is incremental
 *
 * Under a SAPI, PHP parses this format itself, inside `php_request_startup`, and fills
 * `$_POST` and `$_FILES`. A worker is not a SAPI, so nothing parses it for us. The obvious
 * approach — concatenate the body, then parse the string — is exactly what this class exists
 * to avoid: the request body arrives in 64 KiB `BODY` frames, and holding it whole makes peak
 * memory proportional to the upload.
 *
 * So the bytes are fed in as they arrive, and two things happen to them:
 *
 * - A **file part** is written straight to its temporary file. Only a rolling window large
 *   enough to recognise a boundary is ever held in memory.
 * - A **text part** is accumulated, because a `$_POST` value is a string and there is nowhere
 *   else for it to go. That is bounded by `post_max_size`, the same bound PHP applies, so a
 *   form with a pathological text field fails here exactly where it would fail under a SAPI.
 *
 * ## What "correct" means here
 *
 * `php-cgi` is the specification, not the RFC. Where the two disagree this follows PHP, and
 * each such place says so. `tests/fixtures/multipart.php` runs the same payloads through a
 * real `php-cgi` and through this class and asserts the resulting `$_POST` / `$_FILES` are
 * identical — including the *transposed* shape `$_FILES` really has, where `a[b]` becomes
 * `$_FILES['a']['name']['b']` rather than the other way round.
 *
 * ## The one place this deliberately differs from a SAPI
 *
 * PHP consumes the body while parsing it, so `php://input` is empty for a multipart request.
 * A worker keeps the raw body as well (see `Worker::serve()`), so `$request->body` remains the
 * bytes the client sent *and* `$_FILES` is populated. That is a superset of PHP rather than a
 * divergence to be fixed: an application that parsed multipart itself would otherwise lose
 * the data, and every existing consumer of `$request->body` would break.
 */
final class Multipart
{
    /** The keys of a `$_FILES` leaf, in the order PHP emits them. */
    private const FILE_KEYS = ['name', 'full_path', 'type', 'tmp_name', 'error', 'size'];

    private string $boundary;

    /** `CRLF--boundary`, which separates one part's body from the next delimiter. */
    private string $delimiter;

    /** `--boundary`, which is how the body's first delimiter is found in the preamble. */
    private string $opening;

    /** Bytes that have arrived but not yet been consumed. */
    private string $buffer = '';

    private int $state = self::STATE_PREAMBLE;

    private const STATE_PREAMBLE = 0;
    /** Between a boundary and the next part: the rest of the boundary's line. */
    private const STATE_BOUNDARY_LINE = 1;
    private const STATE_HEADERS = 2;
    private const STATE_BODY = 3;
    private const STATE_DONE = 4;

    // The part currently being read.
    private string $partName = '';
    private string $partFilename = '';
    private string $partFullPath = '';
    private string $partType = '';
    private bool $partIsFile = false;

    /** @var resource|null */
    private $partHandle = null;
    private ?string $partPath = null;
    private string $partText = '';

    /** Set when the open file part has been refused, so its bytes are drained and not stored. */
    private ?int $partError = null;

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $files = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private int $fileCount = 0;

    /** A failure that stops the parse, surfaced by `finish()`. */
    private ?string $failure = null;

    private int $maxFiles;
    private int $postMaxSize;
    private int $uploadMaxFilesize;

    /**
     * @param string $contentType the full `Content-Type` header value
     */
    public function __construct(string $contentType)
    {
        $boundary = self::boundaryOf($contentType);
        if ($boundary === null) {
            throw new ProtocolException(
                'a multipart/form-data request has no usable boundary: ' . $contentType
            );
        }

        $this->boundary = $boundary;
        $this->delimiter = "\r\n--" . $boundary;
        $this->opening = '--' . $boundary;

        $this->maxFiles = self::iniInt('max_file_uploads', 20);
        $this->postMaxSize = self::iniBytes('post_max_size', 8 * 1024 * 1024);
        $this->uploadMaxFilesize = self::iniBytes('upload_max_filesize', 2 * 1024 * 1024);

        if (!(bool) ini_get('file_uploads')) {
            // PHP's own behaviour: with `file_uploads = Off` a file part becomes
            // `UPLOAD_ERR_NO_FILE` rather than a parse error.
            $this->maxFiles = 0;
        }
    }

    /**
     * Feed the next slice of the body.
     *
     * Safe to call with any slice size, including one byte at a time: nothing is assumed about
     * where a boundary or a header ends relative to a call.
     */
    public function feed(string $bytes): void
    {
        if ($this->state === self::STATE_DONE || $this->failure !== null) {
            // Bytes past the closing boundary are the epilogue, which PHP discards. Not
            // consuming them keeps the buffer from growing when a body is longer than the
            // frames promised — the caller already has a length check for that.
            return;
        }

        $this->buffer .= $bytes;
        $this->pump();
    }

    /**
     * Mark the end of the body and settle the last part.
     *
     * @throws ProtocolException when the body ended before its closing boundary
     */
    public function finish(): void
    {
        if ($this->failure !== null) {
            $this->discardParts();
            throw new ProtocolException($this->failure);
        }

        if ($this->state !== self::STATE_DONE) {
            // PHP answers 400 for a body with no closing boundary. Reporting it rather than
            // inventing a partial set of files is the same choice: a truncated set of files
            // cannot be told from "the client sent fewer files".
            $this->discardParts();
            throw new ProtocolException('the multipart body ended without its closing boundary');
        }
    }

    /**
     * The `$_POST` equivalent.
     *
     * @return array<string, mixed>
     */
    public function post(): array
    {
        return $this->post;
    }

    /**
     * The `$_FILES` equivalent, in PHP's exact — transposed — shape.
     *
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Delete every temporary file this parser created and nobody has moved.
     *
     * Called by the worker at the end of a request. A file the application moved with
     * {@see Uploads::move()} is no longer at its `tmp_name`, so the unlink fails harmlessly —
     * which is why this can be unconditional.
     */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    // ------------------------------------------------------------------ the pump

    private function pump(): void
    {
        while (true) {
            $progress = match ($this->state) {
                self::STATE_PREAMBLE => $this->pumpPreamble(),
                self::STATE_BOUNDARY_LINE => $this->pumpBoundaryLine(),
                self::STATE_HEADERS => $this->pumpHeaders(),
                self::STATE_BODY => $this->pumpBody(),
                default => false,
            };

            if (!$progress) {
                return;
            }
        }
    }

    /**
     * Discard everything before the first boundary, then consume that boundary.
     *
     * Leaving this state as soon as the boundary token is matched — rather than trying to also
     * consume the rest of its line here — is what makes one-byte slices work. The alternative
     * was to stay in this state until the line ended, which then re-searched the buffer for an
     * opening boundary and threw the next part's bytes away.
     *
     * @return bool whether there is progress and the loop should continue
     */
    private function pumpPreamble(): bool
    {
        $at = strpos($this->buffer, $this->opening);
        if ($at === false) {
            // Keep only enough to recognise a boundary split across two slices.
            $this->keepTail(strlen($this->opening) + 2);

            return false;
        }

        $this->buffer = substr($this->buffer, $at + strlen($this->opening));
        $this->state = self::STATE_BOUNDARY_LINE;

        return true;
    }

    /**
     * Consume the rest of a boundary's line.
     *
     * Two forms are possible: `--` closes the body, and a line ending opens the next part. A
     * body may also carry transport padding — spaces or tabs — before the line ending.
     *
     * @return bool whether there is progress and the loop should continue
     */
    private function pumpBoundaryLine(): bool
    {
        $total = strlen($this->buffer);

        // `--boundary--` closes the body, and anything after it is the epilogue PHP discards.
        if ($total >= 2 && substr($this->buffer, 0, 2) === '--') {
            $this->buffer = '';
            $this->state = self::STATE_DONE;

            return false;
        }

        $cursor = 0;
        while ($cursor < $total && ($this->buffer[$cursor] === ' ' || $this->buffer[$cursor] === "\t")) {
            $cursor++;
        }
        if ($total - $cursor < 2) {
            return false; // the line ending, or the padding, has not fully arrived
        }

        if (substr($this->buffer, $cursor, 2) !== "\r\n") {
            // Neither a closer nor a line ending. PHP reads this as a boundary inside part
            // content rather than a delimiter, and a body like that is not something a client
            // produces.
            $this->failure = 'a multipart boundary was not followed by a line ending';

            return false;
        }

        $this->buffer = substr($this->buffer, $cursor + 2);
        $this->state = self::STATE_HEADERS;

        return true;
    }

    /** @return bool whether there is progress and the loop should continue */
    private function pumpHeaders(): bool
    {
        $end = strpos($this->buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($this->buffer) > 64 * 1024) {
                // A header block that never ends is not multipart, or is far larger than any
                // real part's headers.
                $this->failure = 'a multipart part header block did not end';
            }

            return false; // need more bytes
        }

        $block = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 4);
        $this->beginPart($block);
        $this->state = self::STATE_BODY;

        return true;
    }

    /** @return bool whether there is progress and the loop should continue */
    private function pumpBody(): bool
    {
        $at = $this->findDelimiter();
        if ($at === null) {
            // No complete delimiter in what we hold, so everything except a possible truncated
            // one is part content. Holding back the delimiter's length plus two bytes for the
            // `--` that may follow it is what makes this work when a boundary straddles slices.
            $safe = strlen($this->buffer) - strlen($this->delimiter) - 2;
            if ($safe > 0) {
                $this->writePart(substr($this->buffer, 0, $safe));
                $this->buffer = substr($this->buffer, $safe);
            }

            return false; // need more bytes
        }

        $this->writePart(substr($this->buffer, 0, $at));
        $this->buffer = substr($this->buffer, $at + strlen($this->delimiter));
        $this->closePart();

        // The delimiter's own line is consumed by `pumpBoundaryLine`, which is the same code
        // that consumes the body's first boundary. Keeping it in one place is what stops the
        // two from disagreeing about padding or about the `--` closer.
        $this->state = self::STATE_BOUNDARY_LINE;

        return true;
    }

    /**
     * The offset of the next *real* delimiter in the buffer, or null.
     *
     * A byte sequence matching the delimiter is only a delimiter when what follows it is `--`
     * or a line ending. A file whose content happens to contain `CRLF--boundary` followed by
     * something else must not be truncated there — the classic multipart bug, and the reason
     * this is a search loop rather than a single `strpos`.
     */
    private function findDelimiter(): ?int
    {
        $length = strlen($this->delimiter);
        $total = strlen($this->buffer);
        $from = 0;

        while (true) {
            $at = strpos($this->buffer, $this->delimiter, $from);
            if ($at === false) {
                return null;
            }

            $after = $at + $length;
            if ($total - $after < 2) {
                // Not enough bytes to decide. Waiting is right: emitting these bytes as content
                // would corrupt the part if they turn out to be a delimiter.
                return null;
            }

            if (substr($this->buffer, $after, 2) === '--') {
                return $at;
            }

            // Transport padding — spaces or tabs — is allowed before the line ending.
            $cursor = $after;
            while ($cursor < $total && ($this->buffer[$cursor] === ' ' || $this->buffer[$cursor] === "\t")) {
                $cursor++;
            }
            if ($cursor >= $total) {
                return null; // padding may be incomplete
            }
            if (substr($this->buffer, $cursor, 2) === "\r\n") {
                return $at;
            }

            // A coincidence inside part content. Keep looking one byte on, so an overlapping
            // candidate is not skipped.
            $from = $at + 1;
        }
    }

    /** Hold back at most `$n` bytes, so a boundary split across slices is still found. */
    private function keepTail(int $n): void
    {
        if (strlen($this->buffer) > $n) {
            $this->buffer = substr($this->buffer, -$n);
        }
    }

    // ------------------------------------------------------------------ parts

    private function beginPart(string $headerBlock): void
    {
        $this->partName = '';
        $this->partFilename = '';
        $this->partFullPath = '';
        $this->partType = '';
        $this->partText = '';
        $this->partHandle = null;
        $this->partPath = null;
        $this->partError = null;
        $this->partIsFile = false;

        $disposition = null;
        foreach (explode("\r\n", $headerBlock) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name === 'content-disposition') {
                $disposition = $value;
            } elseif ($name === 'content-type') {
                $this->partType = $value;
            }
        }

        if ($disposition !== null) {
            $params = self::dispositionParams($disposition);
            // Without a `name` a part cannot be placed in either superglobal, and PHP skips it.
            $this->partName = $params['name'] ?? '';
            // A file part is one that carries a `filename`, *including* an empty one — that is
            // how a browser submits a file input the user left blank, and PHP reports it as
            // `UPLOAD_ERR_NO_FILE` rather than treating it as a text field. So the presence of
            // the parameter, not its value, is what decides.
            $this->partIsFile = array_key_exists('filename', $params);
            $this->partFilename = $params['filename'] ?? '';
            // PHP 8.1 added `full_path`, carrying the client's directory structure so a form
            // posting `dir[]` can be reconstructed. It falls back to the filename.
            $this->partFullPath = $params['full_path'] ?? $this->partFilename;
        }

        if ($this->partIsFile) {
            $this->openFilePart();
        }
    }

    private function openFilePart(): void
    {
        if ($this->fileCount >= $this->maxFiles) {
            // PHP's `max_file_uploads`: past the limit the part is refused with
            // `UPLOAD_ERR_NO_FILE` rather than the whole request failing.
            $this->refusePart(UPLOAD_ERR_NO_FILE);

            return;
        }
        $this->fileCount++;

        $dir = self::uploadTmpDir();
        $path = @tempnam($dir, 'php');
        if ($path === false) {
            // PHP distinguishes `UPLOAD_ERR_NO_TMP_DIR` (missing) from `UPLOAD_ERR_CANT_WRITE`
            // (unwritable). `tempnam` cannot, so the directory is tested first.
            $this->refusePart(is_dir($dir) ? UPLOAD_ERR_CANT_WRITE : UPLOAD_ERR_NO_TMP_DIR);

            return;
        }

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            $this->refusePart(UPLOAD_ERR_CANT_WRITE);

            return;
        }

        $this->partPath = $path;
        $this->partHandle = $handle;
        $this->tempFiles[] = $path;
    }

    /** Write bytes to whichever sink this part has, enforcing the upload size limit. */
    private function writePart(string $bytes): void
    {
        if ($this->partIsFile) {
            if ($this->partHandle === null || $bytes === '') {
                return; // refused already, or nothing to write
            }
            $written = @fwrite($this->partHandle, $bytes);
            if ($written === false || $written !== strlen($bytes)) {
                $this->refusePart(UPLOAD_ERR_CANT_WRITE);

                return;
            }
            if ($this->uploadMaxFilesize > 0 && @ftell($this->partHandle) > $this->uploadMaxFilesize) {
                $this->refusePart(UPLOAD_ERR_INI_SIZE);
            }

            return;
        }

        $this->partText .= $bytes;
        if ($this->postMaxSize > 0 && strlen($this->partText) > $this->postMaxSize) {
            // PHP refuses the whole request rather than one field, because `$_POST` has no way
            // to represent "this field was too big".
            $this->failure = 'a form field exceeded post_max_size';
        }
    }

    /**
     * Abandon the open file part and remember why.
     *
     * The file is deleted and its name recorded as `''`, which is what PHP reports for a part
     * it could not keep.
     */
    private function refusePart(int $error): void
    {
        if ($this->partHandle !== null) {
            @fclose($this->partHandle);
            $this->partHandle = null;
        }
        if ($this->partPath !== null) {
            @unlink($this->partPath);
            $this->tempFiles = array_values(array_filter(
                $this->tempFiles,
                fn (string $path): bool => $path !== $this->partPath,
            ));
            $this->partPath = null;
        }
        $this->partError = $error;
    }

    private function closePart(): void
    {
        if (!$this->partIsFile) {
            if ($this->partName !== '') {
                $path = self::parseName($this->partName);
                if ($path !== null) {
                    self::setNested($this->post, $path, $this->partText);
                }
            }

            return;
        }

        if ($this->partError !== null) {
            $this->recordFile('', $this->partError, 0);

            return;
        }

        if ($this->partHandle === null) {
            return; // no part was opened at all
        }

        $size = (int) @ftell($this->partHandle);
        @fclose($this->partHandle);
        $this->partHandle = null;

        if ($this->partFilename === '') {
            // A blank file input: PHP names no temp file and reports `UPLOAD_ERR_NO_FILE`.
            if ($this->partPath !== null) {
                @unlink($this->partPath);
                $this->tempFiles = array_values(array_filter(
                    $this->tempFiles,
                    fn (string $path): bool => $path !== $this->partPath,
                ));
                $this->partPath = null;
            }
            $this->recordFile('', UPLOAD_ERR_NO_FILE, 0);

            return;
        }

        $this->recordFile($this->partPath ?? '', UPLOAD_ERR_OK, $size);
    }

    /**
     * Record one `$_FILES` entry.
     *
     * `$_FILES` is **transposed** relative to every other PHP array, and this is the detail a
     * hand-written test gets wrong: `name="a[b]"` produces
     *
     *     $_FILES['a']['name']['b'], $_FILES['a']['type']['b'], …
     *
     * not `$_FILES['a']['b']['name']`. The field name is the outer key, the six leaf keys are
     * inner, and only the *rest* of the name path nests below them. Verified against php-cgi
     * rather than against the documentation.
     */
    private function recordFile(string $path, int $error, int $size): void
    {
        if ($this->partName === '') {
            return;
        }

        $namePath = self::parseName($this->partName);
        if ($namePath === null || $namePath === []) {
            return;
        }

        /** @var string $field */
        $field = $namePath[0];
        $inner = array_slice($namePath, 1);

        $leaf = [
            'name' => $this->partFilename,
            'full_path' => $this->partFullPath,
            'type' => $this->partType,
            'tmp_name' => $path,
            'error' => $error,
            'size' => $size,
        ];

        if (!isset($this->files[$field]) || !is_array($this->files[$field])) {
            $this->files[$field] = [];
        }

        foreach (self::FILE_KEYS as $key) {
            if ($inner === []) {
                $this->files[$field][$key] = $leaf[$key];

                continue;
            }
            // A bracketed field nests *below* the leaf key, so this key's branch has to exist
            // before anything can be folded into it.
            if (!isset($this->files[$field][$key]) || !is_array($this->files[$field][$key])) {
                $this->files[$field][$key] = [];
            }
            self::setNested($this->files[$field][$key], $inner, $leaf[$key]);
        }
    }

    // ------------------------------------------------------------------ PHP's array folding

    /**
     * Assign `$value` into `$target` at a parsed path, the way PHP's own parser does.
     *
     * `parse_str` already implements this for a string payload, but there is no way to fold a
     * *pre-built value* — a file leaf, whose `tmp_name` is a path and whose `size` is an int —
     * into an existing array through it. So the rule is restated here, and the fixture test is
     * what keeps it honest.
     *
     * Takes a **path** rather than a name. An earlier version re-serialised the path into
     * `[b][]` notation and re-parsed it, which silently dropped a path that was nothing but an
     * append (`name="f[]"`) — the exact shape a repeated file field has.
     *
     * @param array<string, mixed> $target
     * @param list<string|null> $path
     */
    private static function setNested(array &$target, array $path, mixed $value): void
    {
        if ($path === []) {
            return;
        }

        $cursor = &$target;
        $last = count($path) - 1;
        foreach ($path as $index => $key) {
            if ($index === $last) {
                if ($key === null) {
                    $cursor[] = $value;
                } else {
                    $cursor[$key] = $value;
                }

                return;
            }

            if ($key === null) {
                $cursor[] = [];
                $handle = &$cursor[array_key_last($cursor)];
            } else {
                if (!isset($cursor[$key]) || !is_array($cursor[$key])) {
                    $cursor[$key] = [];
                }
                $handle = &$cursor[$key];
            }
            $cursor = &$handle;
            unset($handle);
        }
    }

    /**
     * Split `a[b][][c]` into `['a', 'b', null, 'c']`, or `['a']` for a plain name.
     *
     * `null` is PHP's numeric append. A name with no brackets is a single key even if it
     * contains dots or spaces, which is why this is not a query-string parser.
     *
     * @return list<string|null>|null
     */
    private static function parseName(string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        $open = strpos($name, '[');
        if ($open === false) {
            return [$name];
        }

        $path = [substr($name, 0, $open)];
        $rest = substr($name, $open);

        while ($rest !== '' && $rest[0] === '[') {
            $close = strpos($rest, ']');
            if ($close === false) {
                break;
            }
            $inner = substr($rest, 1, $close - 1);
            $rest = substr($rest, $close + 1);
            // An empty key is PHP's append; anything else is a key.
            $path[] = $inner === '' ? null : $inner;
        }

        return $path;
    }

    // ------------------------------------------------------------------ helpers

    /** The boundary out of a `Content-Type`, or null when there is none or it is unusable. */
    public static function boundaryOf(string $contentType): ?string
    {
        $boundary = self::contentTypeParams($contentType)['boundary'] ?? null;
        if ($boundary === null || $boundary === '') {
            return null;
        }
        // RFC 2046 caps a boundary at 70 characters. Refusing a longer one rather than
        // searching for it keeps a hostile `Content-Type` from making a long slice expensive.
        if (strlen($boundary) > 70) {
            return null;
        }

        return $boundary;
    }

    /** Whether a `Content-Type` names `multipart/form-data`, ignoring its parameters. */
    public static function isMultipart(string $contentType): bool
    {
        return strtolower(trim(explode(';', $contentType, 2)[0])) === 'multipart/form-data';
    }

    /**
     * The `key=value` parameters of a `Content-Type`, with quoted strings unescaped.
     *
     * @return array<string, string>
     */
    private static function contentTypeParams(string $value): array
    {
        $semicolon = strpos($value, ';');

        return self::params($semicolon === false ? '' : substr($value, $semicolon));
    }

    /**
     * The parameters of a `Content-Disposition`.
     *
     * @return array<string, string>
     */
    private static function dispositionParams(string $value): array
    {
        $semicolon = strpos($value, ';');

        return self::params($semicolon === false ? '' : substr($value, $semicolon));
    }

    /**
     * Parse `; key=value; key="quoted \"value\""` into a map.
     *
     * Hand-written rather than `parse_str`, because a disposition parameter is not a query
     * string: `filename="a b;c.txt"` must stay one value, and `parse_str` would split it.
     *
     * @return array<string, string>
     */
    private static function params(string $tail): array
    {
        $params = [];
        $total = strlen($tail);
        $i = 0;

        while ($i < $total) {
            while ($i < $total && ($tail[$i] === ';' || $tail[$i] === ' ' || $tail[$i] === "\t")) {
                $i++;
            }
            if ($i >= $total) {
                break;
            }

            $start = $i;
            while ($i < $total && $tail[$i] !== '=' && $tail[$i] !== ';') {
                $i++;
            }
            $key = strtolower(trim(substr($tail, $start, $i - $start)));

            if ($i >= $total || $tail[$i] !== '=') {
                if ($key !== '') {
                    $params[$key] = '';
                }
                continue;
            }

            $i++; // past '='
            if ($i < $total && $tail[$i] === '"') {
                $i++;
                $value = '';
                while ($i < $total && $tail[$i] !== '"') {
                    if ($tail[$i] === '\\' && $i + 1 < $total) {
                        $i++;
                    }
                    $value .= $tail[$i];
                    $i++;
                }
                $i++; // closing quote
            } else {
                $start = $i;
                while ($i < $total && $tail[$i] !== ';') {
                    $i++;
                }
                $value = trim(substr($tail, $start, $i - $start));
            }

            if ($key !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * The directory a file part is written to.
     *
     * `upload_tmp_dir` first, because that is what an application's `ini_get()` reports and
     * what its own cleanup code assumes; `sys_get_temp_dir()` is PHP's fallback and so this
     * one. The `php` prefix matches what php-cgi produces.
     */
    public static function uploadTmpDir(): string
    {
        $dir = ini_get('upload_tmp_dir');
        if (is_string($dir) && $dir !== '') {
            return $dir;
        }

        return sys_get_temp_dir();
    }

    /** An integer ini setting, tolerating an absent one. */
    private static function iniInt(string $key, int $default): int
    {
        $value = ini_get($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return (int) $value;
    }

    /** A byte-size ini setting such as `8M`. */
    private static function iniBytes(string $key, int $default): int
    {
        $value = ini_get($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return self::toBytes(trim($value)) ?? $default;
    }

    /** Parse `2M`, `512K`, `1G` or a plain number. `0` means unlimited, as in PHP. */
    public static function toBytes(string $value): ?int
    {
        if (preg_match('/^\s*([0-9]+)\s*([KMG]?)\s*$/i', $value, $matches) !== 1) {
            return null;
        }
        $number = (int) $matches[1];

        return match (strtoupper($matches[2])) {
            'K' => $number * 1024,
            'M' => $number * 1024 * 1024,
            'G' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }

    private function discardParts(): void
    {
        if ($this->partHandle !== null) {
            @fclose($this->partHandle);
            $this->partHandle = null;
        }
        $this->cleanup();
    }

    public function __destruct()
    {
        if ($this->partHandle !== null) {
            @fclose($this->partHandle);
        }
    }
}
