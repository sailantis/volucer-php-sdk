<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A request as the server sent it.
 *
 * Deliberately a plain value object rather than PSR-7: the SDK is a transport,
 * not a framework, so it must not force a dependency on anyone's interfaces. An
 * application that wants PSR-7 builds one from this in a few lines (see
 * the worker protocol specification in `sailantis/volucer` and
 * example/worker.php).
 */
final class Request
{
    /**
     * @param array<string, string> $headers header names as received, deduplicated by exact name
     * @param array<string, string> $server CGI-style server variables
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $protocol,
        public readonly array $headers,
        public readonly string $remoteAddr,
        public readonly string $scriptFilename,
        public readonly bool $https,
        public readonly string $body,
        public readonly array $server = [],
    ) {
    }

    /**
     * Look up a header case-insensitively, the way HTTP requires.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    /**
     * Whether this request is a gRPC call.
     *
     * By `content-type`, which is the only thing that says so — deliberately not by the *shape* of
     * the path. An HTTP route can be spelled `/a.B/M`, and treating one as a method call because it
     * looks like one is how a route becomes a service without anyone deciding that. The server makes
     * the same distinction from the same header, and the two must not differ.
     *
     * The suffix rule is the server's: `application/grpc` followed by the end of the value, `;`, `+`
     * or `-`. A bare prefix test would accept `application/grpcx`, which is a *different* media type
     * that happens to begin with the same letters.
     */
    public function isGrpc(): bool
    {
        $type = strtolower(trim($this->contentType() ?? ''));
        if (!str_starts_with($type, 'application/grpc')) {
            return false;
        }

        $rest = substr($type, strlen('application/grpc'));

        return $rest === '' || $rest[0] === ';' || $rest[0] === '+' || $rest[0] === '-';
    }

    /**
     * The service and method this call addresses, or null when the path is not a method path.
     *
     * A single implementation of gRPC's routing rule, and the client's own. gRPC addresses a service by
     * `:path`: `/pkg.Service/Method`, exactly two segments, both protobuf names. Anything else — a
     * third segment, an empty part, a query string — is `null` rather than a lenient guess, because
     * what a wrong answer does is hand an application an arbitrary URL as a method name.
     *
     * The server checks the *same* rule before it delivers a call, so a handler that reaches this and
     * gets `null` is being handed something the server would have refused — which cannot happen through
     * a listener, and is worth knowing when this is called on a `Request` built by hand in a test.
     *
     * @return array{0: string, 1: string}|null `[service, method]`
     */
    public function grpcMethod(): ?array
    {
        // **No `parse_url` here**, and that is the whole of this method's first line.
        //
        // `parse_url('/a.B/M?x=1', PHP_URL_PATH)` answers `/a.B/M` — it *strips* the query. The server
        // does not, because gRPC addresses a method by `:path` alone, so the `?` is simply not a byte a
        // protobuf identifier may contain and the call is refused. Using `parse_url` would therefore
        // make this client and that server disagree about the same `:path`: the server would refuse a
        // call this worker would have served, and the disagreement would be invisible. (This was found
        // by exactly that test — `src/Request.php` and `crates/volucer-core`'s `parse_grpc_path` now
        // state the same rule, and each says so.)
        //
        // What `parse_url` *is* needed for is the absolute form: `http://host/a.B/M` is a request line a
        // proxy could send, and its path is the part after the authority.
        $path = $this->uri;
        if (str_contains($path, '://')) {
            $path = parse_url($path, PHP_URL_PATH);
        }
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return null;
        }

        $rest = substr($path, 1);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return null;
        }

        $service = substr($rest, 0, $slash);
        $method = substr($rest, $slash + 1);
        if (str_contains($method, '/') || $method === '') {
            return null;
        }
        if (!self::isProtoName($service) || !self::isProtoName($method)) {
            return null;
        }

        return [$service, $method];
    }

    /**
     * The service this call addresses, or null when the path is not a method path.
     */
    public function grpcService(): ?string
    {
        return $this->grpcMethod()[0] ?? null;
    }

    /**
     * The method this call addresses, or null when the path is not a method path.
     */
    public function grpcCalledMethod(): ?string
    {
        return $this->grpcMethod()[1] ?? null;
    }

    /**
     * Whether a name is a protobuf full name: identifiers joined by dots.
     *
     * One function for both parts, as in the server, so that the rule a *service* name is checked
     * against and the rule a configuration's `grpc_services` entry is checked against cannot drift.
     * Case-sensitive, because protobuf identifiers are: `a.B` and `a.b` are two different services.
     */
    private static function isProtoName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        foreach (explode('.', $name) as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value for $_SERVER, built the way the CGI environment would.
     *
     * Not a full CGI environment: the server passes only what the protocol
     * carries and the SDK derives the conventional names from it.
     *
     * @return array<string, string>
     */
    public function serverVars(): array
    {
        $server = [
            'REQUEST_METHOD' => $this->method,
            'REQUEST_URI' => $this->uri,
            'SERVER_PROTOCOL' => $this->protocol,
            'REMOTE_ADDR' => $this->remoteAddr,
            'SCRIPT_FILENAME' => $this->scriptFilename,
            'HTTPS' => $this->https ? 'on' : '',
            'CONTENT_LENGTH' => (string) strlen($this->body),
        ];

        $path = parse_url($this->uri, PHP_URL_PATH);
        $query = parse_url($this->uri, PHP_URL_QUERY);
        if (is_string($path)) {
            $server['PATH_INFO'] = $path;
        }
        if (is_string($query)) {
            $server['QUERY_STRING'] = $query;
        }

        foreach ($this->headers as $name => $value) {
            // Content-Type and Content-Length are the two headers CGI gives
            // unprefixed names. This matches the server exactly: it builds the
            // same array for `php-cgi`, and its own test asserts that
            // HTTP_CONTENT_TYPE is never sent. Emitting HTTP_CONTENT_TYPE here
            // instead would leave CONTENT_TYPE absent, and any framework asking
            // for the content type would see none.
            if (strcasecmp($name, 'content-length') === 0) {
                // The framed body length is authoritative, as in the server.
                continue;
            }
            if (strcasecmp($name, 'content-type') === 0) {
                $server['CONTENT_TYPE'] = $value;
                continue;
            }
            $key = 'HTTP_' . strtoupper(strtr($name, '-', '_'));
            $server[$key] = $value;
        }

        // A body with no declared type has no CONTENT_TYPE at all, which PHP
        // treats as form-urlencoded. The server fills this in too, so a worker
        // sees the same environment a `php-cgi` backend would have seen.
        if ($this->body !== '' && !isset($server['CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        }

        foreach ($this->server as $key => $value) {
            $server[$key] = $value;
        }

        return $server;
    }

    /**
     * The query string parsed the way PHP populates $_GET.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {        $query = parse_url($this->uri, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [];
        }

        $params = [];
        parse_str($query, $params);

        return $params;
    }

    /**
     * The cookies parsed the way PHP populates $_COOKIE.
     *
     * Nothing else fills $_COOKIE in a worker, so an application that reads it
     * — or that wants the session id PHP would have found in it — has to be
     * given the mapping explicitly.
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        $header = $this->header('cookie');
        if ($header === null || $header === '') {
            return [];
        }

        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $name = urldecode(trim(substr($pair, 0, $separator)));
            if ($name === '') {
                continue;
            }

            // A repeated name: the first wins, as in PHP.
            $cookies[$name] ??= urldecode(substr($pair, $separator + 1));
        }

        return $cookies;
    }
}
