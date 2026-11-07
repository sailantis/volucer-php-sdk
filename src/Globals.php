<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Populate CGI superglobals for a request and clear them before the worker handles another.
 * Install for every HTTP request and pair with {@see Globals::reset()} in a `finally` to prevent
 * data leaking between requests. gRPC calls skip this because their bodies are not form data.
 *
 * Installation is enabled by default for SAPI compatibility. Use
 * {@see Worker::withoutCgiGlobals()} when the application relies only on {@see Request}.
 *
 * `$_ENV` remains controlled by PHP's `variables_order`. `$_REQUEST` is built from the request
 * using PHP's `request_order`; `$_SESSION` is started normally after installation.
 */
final class Globals
{
    /** The superglobals this class owns. Everything here is restored by {@see Globals::reset()}. */
    private const OWNED = ['_SERVER', '_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST'];

    /**
     * Replace the owned superglobals with values from the request. Repeated calls do not merge
     * state; multipart `$post` and `$files` use the already-parsed body. Empty `$post` falls back
     * to {@see Request::post()} for urlencoded forms.
     *
     * @param array<string, mixed> $files the `$_FILES` array, from {@see Multipart::files()}
     */
    public static function install(Request $request, array $post, array $files): void
    {
        $_SERVER  = $request->serverVars();
        $_GET     = $request->query();
        $_COOKIE  = $request->cookies();
        $_POST    = $post !== [] ? $post : $request->post();
        $_FILES   = $files;
        $_REQUEST = $request->input();
    }

    /**
     * Clear all owned superglobals, including after a handler throws, to prevent cross-request leaks.
     */
    public static function reset(): void
    {
        $_SERVER  = [];
        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];
        $_FILES   = [];
        $_REQUEST = [];
    }

    /** Return the names of the superglobals this class manages. */
    public static function owned(): array
    {
        return self::OWNED;
    }
}
