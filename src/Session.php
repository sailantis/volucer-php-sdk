<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Bridges native PHP sessions to worker requests and responses.
 *
 * Reads the session id from request cookies and adds `Set-Cookie` to the response.
 * The application must still call `session_start()` to create a session.
 */
final class Session
{
    /** Allowed session-id characters; reject other client-provided values. */
    private const ID_PATTERN = '/^[A-Za-z0-9,\-]+$/';

    /** The id the client presented, so it is not sent a cookie it already has. */
    private ?string $presented = null;

    /** Begin a request using its parsed cookies. */
    public function begin(Request $request): void
    {
        $this->beginWithCookies($request->cookies());
    }

    /**
     * Begin a request from a parsed cookie map.
     *
     * Closes and clears any prior session, then adopts a validated client id.
     * The application must call `session_start()` to open a session.
     *
     * @param array<string, string> $cookies as the client sent them, i.e. `$_COOKIE`
     */
    public function beginWithCookies(array $cookies): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        self::disablePhpCookies();

        $name = session_name();
        $candidate = ($name !== false && isset($cookies[$name])) ? $cookies[$name] : null;

        $this->presented = ($candidate !== null && preg_match(self::ID_PATTERN, $candidate) === 1)
            ? $candidate
            : null;

        // Reset the id even when no cookie was sent, preventing session reuse across requests.
        session_id($this->presented ?? '');
    }

    /**
     * Add a new session cookie to the response, then end and reset the session.
     *
     * No cookie is added if the session was not started or the client already has its id.
     */
    public function applyTo(Response $response): Response
    {
        $cookie = $this->cookie();
        $this->end();

        return $cookie === null ? $response : $response->withHeader('Set-Cookie', $cookie);
    }

    /** Return a new `Set-Cookie` value, or null if none is needed. */
    public function cookie(): ?string
    {
        $id = session_id();

        if ($id === '' || $id === $this->presented) {
            return null;
        }

        return self::header($id, session_name(), session_get_cookie_params());
    }

    /** Close the session and clear its id and data before the next request. */
    public function end(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        session_id('');
        $this->presented = null;
    }

    /**
     * Build a `Set-Cookie` value using PHP's session settings.
     *
     * @param string|false         $name   from `session_name()`
     * @param array<string, mixed> $params from `session_get_cookie_params()`
     */
    public static function header(string $id, string|false $name, array $params): string
    {
        $parts = [rawurlencode($name === false || $name === '' ? 'PHPSESSID' : $name)
            . '=' . rawurlencode($id)];

        $lifetime = (int) ($params['lifetime'] ?? 0);
        if ($lifetime > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', time() + $lifetime) . ' GMT';
            $parts[] = 'Max-Age=' . $lifetime;
        }

        $parts[] = 'Path=' . self::nonEmpty($params['path'] ?? null, '/');

        $domain = self::nonEmpty($params['domain'] ?? null, '');
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        if (!empty($params['secure'])) {
            $parts[] = 'Secure';
        }

        if (!empty($params['httponly'])) {
            $parts[] = 'HttpOnly';
        }

        $samesite = self::nonEmpty($params['samesite'] ?? null, '');
        if ($samesite !== '') {
            $parts[] = 'SameSite=' . $samesite;
        }

        return implode('; ', $parts);
    }

    /** Whether PHP's session cookie setting is still enabled. */
    public static function phpCookiesEnabled(): bool
    {
        return (bool) ini_get('session.use_cookies');
    }

    /**
     * Disable PHP's cookie and cache-limiter headers when possible.
     *
     * The worker adds session cookies to its response; these settings prevent
     * PHP from attempting to send headers through the unavailable SAPI.
     */
    private static function disablePhpCookies(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
    }

    private static function nonEmpty(mixed $value, string $fallback): string
    {
        return (is_string($value) && $value !== '') ? $value : $fallback;
    }
}
