<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Upload helpers for CGI and worker mode.
 *
 * Worker-created temporary files are not recognized by PHP's upload checks, so
 * {@see Uploads::move()} falls back to `rename()`. This class does not emulate
 * `is_uploaded_file()`; use {@see Uploads::movedFromClient()} for PHP's actual result.
 */
final class Uploads
{
    /**
     * Move an uploaded file, preserving PHP's CGI handling and supporting worker files.
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

        // Preserve PHP's upload validation in CGI mode.
        if (@move_uploaded_file($source, $target)) {
            return true;
        }

        // Worker-created uploads are not recognized by PHP; rename supports those files.
        return @rename($source, $target);
    }

    /**
     * Whether the upload completed and has a temporary file.
     *
     * @param array{tmp_name?: string, error?: int} $file
     */
    public static function isOk(array $file): bool
    {
        return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && (string) ($file['tmp_name'] ?? '') !== '';
    }

    /**
     * Return PHP's `is_uploaded_file()` result for the temporary path.
     *
     * Worker-created uploads may return false because PHP did not create them.
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
     * Delete an uploaded file early; the worker also cleans it up at request end.
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
     * Return a PHP-style message for the upload error, or null on success.
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
     * Return the file size from disk, or zero when unavailable.
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
