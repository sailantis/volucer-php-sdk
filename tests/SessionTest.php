<?php

declare(strict_types=1);

/**
 * Sessions in a worker, where no SAPI is doing it for the application.
 *
 * Deliberately run *without* any `-d` session flags, because the point being
 * made is that the class configures itself — a worker started with a stock
 * `php` command still gets working sessions. What a CLI worker cannot do is
 * deliver `header()` output, which is why the adapter has to carry the cookie
 * rather than PHP.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Request;
use Volucer\Response;
use Volucer\Session;

/** The ini values the suite found, so each case starts from a known state. */
$useCookies = ini_get('session.use_cookies');
$cacheLimiter = ini_get('session.cache_limiter');

/**
 * Session state is global and outlives a case, exactly as it outlives a request
 * in the worker this class exists for.
 */
$reset = static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_SESSION = [];
    session_id('');
    $_COOKIE = [];
};

/** A request carrying the given cookies, as the protocol would deliver them. */
$request = static function (array $cookies): Request {
    $pairs = [];
    foreach ($cookies as $name => $value) {
        $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
    }

    return new Request(
        method: 'GET',
        uri: '/',
        protocol: 'HTTP/1.1',
        headers: $pairs === [] ? [] : ['cookie' => implode('; ', $pairs)],
        remoteAddr: '127.0.0.1',
        scriptFilename: '/app.php',
        https: false,
        body: '',
    );
};

// A worker's SAPI cannot deliver headers, which is why the cookie is carried
// out by hand. Under CLI this is literal: `headers_list()` stays empty.
$reset();
Assert::same('cli', PHP_SAPI, 'this suite describes the CLI worker');
session_start();
session_write_close();
Assert::same([], headers_list(), 'nothing PHP writes reaches a header list');

// The settings PHP needs relaxed are relaxed by the class, so a worker does not
// have to be started with matching `-d` flags.
$reset();
ini_set('session.use_cookies', '1');
ini_set('session.cache_limiter', 'nocache');
$session = new Session();
$session->begin($request([]));
Assert::same(false, Session::phpCookiesEnabled(), 'PHP will not try to send the cookie');
Assert::same('', (string) ini_get('session.cache_limiter'), 'and emits no cache limiter headers');

// Nothing started a session, so nothing is offered.
$reset();
$session = new Session();
$session->begin($request([]));
Assert::same(null, $session->cookie(), 'a request with no session gets no cookie');

// The primitive takes cookies directly, for an adapter whose request is PSR-7
// or a framework's own object rather than a Volucer\Request.
$reset();
$session = new Session();
$session->beginWithCookies([(string) session_name() => 'feedfacecafebeef']);
Assert::same('feedfacecafebeef', session_id(), 'beginWithCookies adopts the same way begin does');

// The client's own id is adopted, and not echoed back as a needless
// `Set-Cookie` on every single response.
$reset();
$session = new Session();
$session->begin($request([(string) session_name() => 'abcdef0123456789']));
Assert::same('abcdef0123456789', session_id(), 'the client\'s session id is adopted');
session_start();
Assert::same('abcdef0123456789', session_id(), 'and survives session_start()');
Assert::same(null, $session->cookie(), 'and is not sent back');

// The id from a cookie becomes part of a file name, so anything that is not a
// session id must not reach the session handler.
foreach (['../../etc/passwd', 'a b', 'id;rm -rf /', '', 'x/y', 'sid=1'] as $hostile) {
    $reset();
    $session = new Session();
    $session->begin($request([(string) session_name() => $hostile]));
    Assert::same('', session_id(), sprintf('a hostile session id is refused: %s', var_export($hostile, true)));
}

// A session the application started is offered to the client.
$reset();
$session = new Session();
$session->begin($request([]));
session_start();
$id = session_id();
Assert::same(true, $id !== '', 'a new session has an id');
$cookie = $session->cookie();
Assert::same(true, is_string($cookie), 'and is offered to the client');
Assert::same(true, str_starts_with((string) $cookie, rawurlencode((string) session_name()) . '=' . $id), 'with the id as its value');
Assert::same(true, str_contains((string) $cookie, 'Path=/'), 'and a default path');

// The whole point: a client presenting nothing must not be handed the previous
// client's session, because `session_id()` outlives the request.
$reset();
$first = new Session();
$first->begin($request([(string) session_name() => 'aaaaaaaaaaaaaaaa']));
session_start();
$firstId = session_id();
$first->end();

$second = new Session();
$second->begin($request([]));
Assert::same('', session_id(), 'the leftover id was cleared before the next request');
session_start();
Assert::same(true, session_id() !== $firstId, 'so the next client gets a different session');

// A session that ends must not leave its data behind for the next request.
$reset();
$session = new Session();
$session->begin($request([]));
session_start();
$_SESSION['flash'] = 'from the last request';
$session->end();
Assert::same([], $_SESSION, 'end() forgets the session data');
Assert::same('', session_id(), 'and the id');
Assert::same(PHP_SESSION_NONE, session_status(), 'and leaves no session active');

// The cookie value is a pure function of PHP's settings, so it can be pinned
// without depending on the machine's php.ini.
Assert::same(
    'PHPSESSID=abc123; Path=/',
    Session::header('abc123', 'PHPSESSID', []),
    'the minimum cookie is a name, a value and a default path',
);
Assert::same(
    'PHPSESSID=abc123; Path=/; Domain=example.test; Secure; HttpOnly; SameSite=Lax',
    Session::header('abc123', 'PHPSESSID', [
        'path' => '/',
        'domain' => 'example.test',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]),
    'every attribute PHP supports is carried in PHP\'s own order',
);
Assert::same(
    'PHPSESSID=abc123; Path=/',
    Session::header('abc123', false, ['path' => '']),
    'an unset session name and an empty path fall back to the defaults',
);

// A persistent cookie carries both expiry forms, because a client that does not
// understand one of them must still get the other.
$cookie = Session::header('abc123', '_s', ['lifetime' => 3600, 'path' => '/']);
Assert::contains('Expires=', $cookie, 'a persistent cookie has an Expires');
Assert::contains('Max-Age=3600', $cookie, 'and a Max-Age');
Assert::same(true, str_starts_with($cookie, '_s=abc123;'), 'with the configured name');

// A name or a value that would break the header must be encoded, not trusted.
$cookie = Session::header('a b;c', 'my name', []);
Assert::same(true, str_starts_with($cookie, 'my%20name=a%20b%3Bc;'), 'the session name and id are encoded');
Assert::same(false, str_contains($cookie, 'a b;c'), 'so the raw value cannot break out of the header');

// The response integration: the cookie lands in the header list, and nothing
// else about the response changes.
$reset();
$session = new Session();
$session->begin($request([]));
session_start();
$id = session_id();
$response = $session->applyTo(Response::text('ok'));
Assert::same('ok', $response->body, 'applyTo leaves the body alone');
Assert::same(2, count($response->headers), 'and adds exactly one header to the one text() set');
Assert::same(
    ['Content-Type', 'text/plain; charset=utf-8'],
    $response->headers[0],
    'leaving the response\'s own headers in order ahead of it',
);
Assert::same('Set-Cookie', $response->headers[1][0], 'which is a Set-Cookie');
Assert::same(true, str_starts_with($response->headers[1][1], rawurlencode((string) session_name()) . '=' . $id), 'naming the session');
Assert::same(false, str_contains($response->headers[1][1], "\r\n"), 'and carries no header injection');

// applyTo ends the request as well, so the next request cannot inherit any of
// this one. Asserted separately from the cookie because it is the half a caller
// would otherwise have to remember.
Assert::same('', session_id(), 'applyTo cleared the id');
Assert::same([], $_SESSION, 'and the session data');
Assert::same(PHP_SESSION_NONE, session_status(), 'and left no session active');

// A response that earned no cookie is returned untouched.
$reset();
$session = new Session();
$session->begin($request([]));
$response = $session->applyTo(Response::text('ok'));
Assert::same([['Content-Type', 'text/plain; charset=utf-8']], $response->headers, 'no session means no Set-Cookie');

// The ordering trap this class is shaped to prevent: end() clears the id, so a
// caller that ends first and asks for the cookie second gets nothing, silently.
// applyTo() cannot be used that way, and this pins why the two are fused.
$reset();
$session = new Session();
$session->begin($request([]));
session_start();
$session->end();
Assert::same(null, $session->cookie(), 'reading the cookie after end() yields nothing — the silent failure');

// Calling end() as well as applyTo() is permitted rather than punished.
$reset();
$session = new Session();
$session->begin($request([]));
session_start();
$response = $session->applyTo(Response::text('ok'));
$session->end();
Assert::same('Set-Cookie', $response->headers[1][0], 'a careful caller that ends twice still gets its cookie');

// Leave the process as it was found.
$reset();
ini_set('session.use_cookies', (string) $useCookies);
ini_set('session.cache_limiter', (string) $cacheLimiter);

exit(Assert::report('SessionTest'));
