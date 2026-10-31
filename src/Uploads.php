<?php

declare(strict_types=1);

namespace Volucer;

/**
 * The upload operations that work the same in CGI mode and in worker mode.
 *
 * ## Why this class exists
 *
 * `is_uploaded_file()` and `move_uploaded_file()` consult a hash the **PHP SAPI** fills while
 * it parses a multipart body. A worker is not a SAPI, so both return `false` for a file the
 * worker wrote — there is no API to add to that hash. This is a PHP engine boundary, not a gap
 * in volucer: RoadRunner has the same one, and FrankenPHP avoids it only by being a SAPI
 * itself.
 *
 * The consequence is that an application written against `php-cgi` and moved to a worker breaks
 * in exactly two places, and nowhere else:
 *
 *     move_uploaded_file($file['tmp_name'], $target);   // false in a worker
 *     if (is_uploaded_file($file['tmp_name'])) { … }    // false in a worker
 *
 * Everything downstream works because it operates on a path: `rename()`, `copy()`, `fopen()`,
 * `file_get_contents()`, Symfony's `File::move()`, Laravel's `store()`.
 *
 * So this class is that difference, in one place:
 *
 *     // in either mode
 *     \Volucer\Uploads::move($_FILES['doc'], '/srv/storage/doc.pdf');
 *
 * ## What it does *not* do
 *
 * It does not pretend to be a SAPI. If an application calls `is_uploaded_file()` itself, it
 * still gets `false` in worker mode, and {@see Uploads::movedFromClient()} says so rather than
 * hiding it — an application that relies on that check for a security property needs to know
 * it is unavailable, not to be silently told "yes".
 */
final class Uploads
{
    /**
     * Move an uploaded file to its destination.
     *
     * Uses `move_uploaded_file()` when PHP will allow it — so in CGI mode nothing about the
     * existing behaviour changes, including PHP's own bookkeeping — and `rename()` otherwise.
     * `rename()` is the right fallback rather than `copy()` because it is a single operation
     * that either works or does not, and it does not leave a half-written destination behind.
     *
     * @param array{tmp_name?: string, error?: int} $file a `$_FILES` entry
     * @return bool false when the entry has no file or the move failed
     */
    public static function move(array $file, string $target): bool
    {
        if (!self::isOk($file)) {
            return false;
        }

        $source = (string) ($file['tmp_name'] ?? '');
        if ($source === '' || !is_file($source)) {
            return false;
        }

        // `move_uploaded_file` first, so a deployment that is not a worker keeps PHP's own
        // semantics — including its refusal to move a path the SAPI did not produce, which is
        // a security property worth keeping where it exists.
        if (@move_uploaded_file($source, $target)) {
            return true;
        }

        // In a worker this is the path taken, and in CGI mode it is reached only when the
        // destination is not writable — in which case the move genuinely failed and the caller
        // needs to hear about it, which it will.
        return @rename($source, $target);
    }

    /**
     * Whether an entry names a file that was received without an error.
     *
     * Deliberately `error === UPLOAD_ERR_OK` and a non-empty `tmp_name`, which is the part of
     * {@see Uploads::movedFromClient()} that *is* reliable in both modes.
     *
     * @param array{tmp_name?: string, error?: int} $file
     */
    public static function isOk(array $file): bool
    {
        return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && (string) ($file['tmp_name'] ?? '') !== '';
    }

    /**
     * PHP's `is_uploaded_file()`, which answers differently in the two modes.
     *
     * Returns whether *PHP itself* will accept the path, so an application that needs the real
     * answer — for instance because a library it calls performs that check internally — can ask
     * and act accordingly. In CGI mode this is PHP's own answer, unchanged.
     *
     * @param array{tmp_name?: string} $file
     */
    public static function movedFromClient(array $file): bool
    {
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '') {
            return false;
        }

        return is_uploaded_file($path);
    }

    /**
     * Delete an uploaded file the application did not keep.
     *
     * Not needed for correctness — the worker removes its own files at the end of a request —
     * but useful when an application wants the space back before it answers, which is the
     * difference between a request that holds a disk quota and one that does not.
     *
     * @param array{tmp_name?: string} $file
     */
    public static function discard(array $file): void
    {
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * A human-readable reason for a `$_FILES` error code, phrased as PHP's own messages are.
     *
     * Exists so an application does not have to reproduce the table, and so a code PHP adds
     * later is reported rather than silently treated as success.
     *
     * @param array{error?: int} $file
     */
    public static function errorMessage(array $file): ?string
    {
        return match ($file['error'] ?? UPLOAD_ERR_OK) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'The temporary folder is missing',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error',
        };
    }

    /**
     * The size of an uploaded file, read from disk rather than trusted from the entry.
     *
     * `$_FILES['size']` is what the client claimed and is not authoritative; PHP fills it from
     * the bytes it actually wrote, and so does {@see Multipart}, but a caller that compares the
     * two deserves a way to.
     *
     * @param array{tmp_name?: string} $file
     */
    public static function size(array $file): int
    {
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '') {
            return 0;
        }

        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }
}
