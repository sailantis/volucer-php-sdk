<?php

declare(strict_types=1);

namespace Volucer;

/**
 * The CGI superglobals, filled as a SAPI would fill them and cleared afterwards.
 *
 * ## Why a worker has to do this itself
 *
 * This is the second half of "an application can move between `php-cgi` and a worker without
 * changes". A SAPI builds `$_SERVER`, `$_GET`, `$_COOKIE`, `$_POST` and `$_FILES` before the
 * script runs and throws them away after. A worker process serves many requests, so it must do
 * the same — and, because the process lives on, it must also *undo* it, or one request's form
 * data becomes visible to the next one. That leak is the highest-severity failure this class
 * can have, so every install is paired with {@see Globals::reset()} in a `finally`.
 *
 * ## What is deliberately not filled
 *
 * `$_ENV` and `$_REQUEST`. `$_ENV` is a process setting PHP's `variables_order` decides, and a
 * worker has no meaningful environment to project. `$_REQUEST` is a merge whose contents depend
 * on `request_order`; filling it from a guessed order would be worse than leaving it to the
 * application, and PHP 8 deprecates reading it without a declared order anyway.
 *
 * `$_SESSION` is not a superglobal PHP fills from a request either — it is started by
 * `session_start()`, which reads `$_COOKIE` and the session handler. Those both work here, so a
 * worker's `session_start()` behaves as it does under a SAPI as long as it is called after
 * {@see Globals::install()}.
 */
final class Globals
{
    /** The superglobals this class owns. Everything here is restored by {@see Globals::reset()}. */
    private const OWNED = ['_SERVER', '_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST'];

    /**
     * Fill the superglobals from a request.
     *
     * Safe to call repeatedly: each call replaces the previous request's values rather than
     * merging with them, so a caller that forgot to reset cannot accumulate.
     *
     * @param array<string, mixed> $files the `$_FILES` array, from {@see Multipart::files()}
     */
    public static function install(Request $request, array $post, array $files): void
    {
        $_SERVER = self::server($request);
        $_GET = $request->query();
        $_COOKIE = $request->cookies();
        $_POST = $post;
        $_FILES = $files;
        // Left empty rather than merged, because `request_order` is a policy an application
        // chooses and this has no way to know it. A request that reads `$_REQUEST` sees an empty
        // array, which is visibly wrong rather than quietly the wrong order.
        $_REQUEST = [];
    }

    /**
     * Clear every superglobal this class filled.
     *
     * Called in a `finally`, so it runs even when the handler threw. The point is not tidiness:
     * a worker that leaves `$_FILES` populated hands the next request's user a path to the
     * previous one's upload, which is a cross-user file disclosure.
     */
    public static function reset(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];
    }

    /** The names this class writes, for a test that asserts nothing else is touched. */
    public static function owned(): array
    {
        return self::OWNED;
    }

    /**
     * The `$_SERVER` array, in the shape a CGI environment has.
     *
     * @return array<string, string>
     */
    private static function server(Request $request): array
    {
        return $request->serverVars();
    }
}
