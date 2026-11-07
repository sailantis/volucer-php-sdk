<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Incremental multipart/form-data parser for worker requests.
 *
 * File parts are streamed to temporary files; text fields are bounded by
 * `post_max_size`. The resulting `$_POST` and transposed `$_FILES` shapes follow PHP.
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

    /** Feed the next body slice; boundaries may span calls. */
    public function feed(string $bytes): void
    {
        if ($this->state === self::STATE_DONE || $this->failure !== null) {
            // PHP discards epilogue bytes; leave them to the caller's body-length check.
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
            // Reject incomplete bodies instead of returning partial form data.
            $this->discardParts();
            throw new ProtocolException('the multipart body ended without its closing boundary');
        }
    }

    /** @return array<string, mixed> Parsed form fields. */
    public function post(): array
    {
        return $this->post;
    }

    /** @return array<string, mixed> Uploaded files in PHP's transposed shape. */
    public function files(): array
    {
        return $this->files;
    }

    /** Delete temporary files that the application has not moved. */
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
     * Find the opening boundary and switch to its line state.
     *
     * @return bool whether parsing can continue
     */
    private function pumpPreamble(): bool
    {
        $at = strpos($this->buffer, $this->opening);
        if ($at === false) {
            // Retain a possible partial boundary.
            $this->keepTail(strlen($this->opening) + 2);

            return false;
        }

        $this->buffer = substr($this->buffer, $at + strlen($this->opening));
        $this->state = self::STATE_BOUNDARY_LINE;

        return true;
    }

    /**
     * Consume a boundary line, including optional transport padding.
     *
     * @return bool whether parsing can continue
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
            // A boundary must be followed by a closing marker or line ending.
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
                // Reject an unterminated or oversized header block.
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
            // Keep a possible partial delimiter in the buffer.
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

        // Parse all boundary lines using the same state handler.
        $this->state = self::STATE_BOUNDARY_LINE;

        return true;
    }

    /**
     * Find the next delimiter followed by `--` or a line ending.
     *
     * @return int|null delimiter offset
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
                // Wait until enough bytes arrive to classify the candidate.
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

            // This match is part content; check for overlapping candidates.
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
            // PHP skips unnamed parts.
            $this->partName = $params['name'] ?? '';
            // An empty filename still identifies a file part; PHP reports it as NO_FILE.
            $this->partIsFile = array_key_exists('filename', $params);
            $this->partFilename = $params['filename'] ?? '';
            // Preserve the submitted path when available.
            $this->partFullPath = $params['full_path'] ?? $this->partFilename;
        }

        if ($this->partIsFile) {
            $this->openFilePart();
        }
    }

    private function openFilePart(): void
    {
        if ($this->fileCount >= $this->maxFiles) {
            // PHP reports excess files as UPLOAD_ERR_NO_FILE.
            $this->refusePart(UPLOAD_ERR_NO_FILE);

            return;
        }
        $this->fileCount++;

        $dir = self::uploadTmpDir();
        $path = @tempnam($dir, 'php');
        if ($path === false) {
            // Distinguish a missing directory from an unwritable one.
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
            // PHP rejects the whole request when post_max_size is exceeded.
            $this->failure = 'a form field exceeded post_max_size';
        }
    }

    /** Close and delete the current file part, recording its upload error. */
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
            // Empty filenames have no temporary file in PHP.
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
     * Record a file using PHP's transposed `$_FILES` shape.
     *
     * For `name="a[b]"`, the nested key appears below each file attribute.
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
            // Nest bracketed field names below each file attribute.
            if (!isset($this->files[$field][$key]) || !is_array($this->files[$field][$key])) {
                $this->files[$field][$key] = [];
            }
            self::setNested($this->files[$field][$key], $inner, $leaf[$key]);
        }
    }

    // ------------------------------------------------------------------ PHP's array folding

    /**
     * Assign a value at a parsed PHP array path, including append segments.
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
     * Split a bracketed field name into path segments; null represents `[]`.
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
        // RFC 2046 limits boundary length to 70 characters.
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
     * Parse semicolon-separated parameters, preserving quoted values.
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

    /** Return PHP's upload temp directory, falling back to the system temp directory. */
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
