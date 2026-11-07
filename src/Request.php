<?php

declare(strict_types=1);

namespace Volucer;

/** A request received from the server, represented as a dependency-free value object. */
final class Request
{
    /** @var array<string, string>|null Cached CGI-style server variables. */
    private ?array $serverVarsCache = null;

    /** @var array<string, mixed>|null */
    private ?array $queryCache = null;

    /** @var array<string, mixed>|null */
    private ?array $inputCache = null;

    /** @var bool|null */
    private ?bool $grpcCache = null;

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
        public readonly array $post = [],
        public readonly array $files = [],
    ) {}

    /** Look up a header case-insensitively. */
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
     * Return parsed form fields for multipart or URL-encoded POST requests.
     *
     * Other body types are available through {@see Request::$body}.
     *
     * @return array<string, mixed>
     */
    public function post(): array
    {
        if ($this->post !== []) {
            return $this->post;
        }

        if (strcasecmp($this->method, 'POST') !== 0 || $this->body === '') {
            return [];
        }

        if (!$this->hasMediaType('application/x-www-form-urlencoded')) {
            return [];
        }

        $params = [];
        parse_str($this->body, $params);

        return $params;
    }

    /**
     * Return uploaded files in the shape used by `$_FILES`.
     *
     * Files are already streamed to disk; the raw multipart body remains available separately.
     *
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Whether the content type identifies a gRPC request.
     *
     * Accepts `application/grpc` followed by end-of-value, `;`, `+`, or `-`.
     */
    public function isGrpc(): bool
    {
        // Cache the media-type test used by the request dispatcher.
        if ($this->grpcCache !== null) {
            return $this->grpcCache;
        }

        $type = strtolower(trim($this->contentType() ?? ''));
        if (!str_starts_with($type, 'application/grpc')) {
            return $this->grpcCache = false;
        }

        $rest = substr($type, strlen('application/grpc'));

        return $this->grpcCache = ($rest === '' || $rest[0] === ';' || $rest[0] === '+' || $rest[0] === '-');
    }

    /**
     * Return the gRPC service and method from `/service/method`, or null if invalid.
     *
     * @return array{0: string, 1: string}|null `[service, method]`
     */
    public function grpcMethod(): ?array
    {
        // Validate the raw path so query text cannot be stripped from an invalid method path.
        // Parse only absolute-form request targets to extract their path.
        $path = $this->uri;
        if (str_contains($path, '://')) {
            $path = parse_url($path, PHP_URL_PATH);
        }
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return null;
        }

        $rest  = substr($path, 1);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return null;
        }

        $service = substr($rest, 0, $slash);
        $method  = substr($rest, $slash + 1);
        if (str_contains($method, '/') || $method === '') {
            return null;
        }
        if (!self::isProtoName($service) || !self::isProtoName($method)) {
            return null;
        }

        return [$service, $method];
    }

    /** Return the gRPC service name, or null if the path is invalid. */
    public function grpcService(): ?string
    {
        return $this->grpcMethod()[0] ?? null;
    }

    /** Return the gRPC method name, or null if the path is invalid. */
    public function grpcCalledMethod(): ?string
    {
        return $this->grpcMethod()[1] ?? null;
    }

    /** Whether a dot-separated name contains valid, case-sensitive protobuf identifiers. */
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
     * Build CGI-style server variables from request metadata.
     *
     * `SCRIPT_NAME` and `PHP_SELF` use the request path; `DOCUMENT_ROOT` is
     * derived from `scriptFilename`. Explicit server values override derived ones.
     *
     * @return array<string, string>
     */
    public function serverVars(): array
    {
        if ($this->serverVarsCache !== null) {
            return $this->serverVarsCache;
        }

        $server = [
            'REQUEST_METHOD'  => $this->method,
            'REQUEST_URI'     => $this->uri,
            'SERVER_PROTOCOL' => $this->protocol,
            'REMOTE_ADDR'     => $this->remoteAddr,
            'SCRIPT_FILENAME' => $this->scriptFilename,
            'HTTPS'           => $this->https ? 'on' : '',
            'CONTENT_LENGTH'  => (string) strlen($this->body),
        ];

        $path  = parse_url($this->uri, PHP_URL_PATH);
        $query = parse_url($this->uri, PHP_URL_QUERY);
        if (is_string($path) && $path !== '') {
            // These variables describe the request path, not the front controller.
            $server['PATH_INFO']   = $path;
            $server['SCRIPT_NAME'] = $path;
            $server['PHP_SELF']    = $path;
        }
        if (is_string($query)) {
            $server['QUERY_STRING'] = $query;
        }

        if ($this->scriptFilename !== '') {
            $directory = str_replace('\\', '/', dirname($this->scriptFilename));
            if ($directory !== '.' && $directory !== '') {
                $server['DOCUMENT_ROOT'] = $directory;
            }
        }

        foreach ($this->headers as $name => $value) {
            // Convert header names to CGI's uppercase, underscore form.
            $key = 'HTTP_' . strtr($name, self::HEADER_NAME_FROM, self::HEADER_NAME_TO);

            // CGI exposes these two headers without the HTTP_ prefix.
            if ($key === 'HTTP_CONTENT_LENGTH') {
                // The framed body length is authoritative, as in the server.
                continue;
            }
            if ($key === 'HTTP_CONTENT_TYPE') {
                $server['CONTENT_TYPE'] = $value;
                continue;
            }

            $server[$key] = $value;
        }

        // Match PHP's default form content type when a non-empty body has no type.
        if ($this->body !== '' && !isset($server['CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        }

        foreach ($this->server as $key => $value) {
            $server[$key] = $value;
        }

        return $this->serverVarsCache = $server;
    }

    /** The `strtr` pair that upper-cases an ASCII header name and turns `-` into `_`. */
    private const HEADER_NAME_FROM = 'abcdefghijklmnopqrstuvwxyz-';
    private const HEADER_NAME_TO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_';

    /** Return query parameters parsed like PHP's `$_GET`. @return array<string, mixed> */
    public function query(): array
    {
        if ($this->queryCache !== null) {
            return $this->queryCache;
        }

        $query = parse_url($this->uri, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $this->queryCache = [];
        }

        $params = [];
        parse_str($query, $params);

        return $this->queryCache = $params;
    }

    /**
     * Whether the request's `Content-Type` names this media type, ignoring any parameters.
     */
    private function hasMediaType(string $mediaType): bool
    {
        $header = $this->contentType();
        if ($header === null) {
            return false;
        }

        $semicolon = strpos($header, ';');

        return strcasecmp(trim($semicolon === false ? $header : substr($header, 0, $semicolon)), $mediaType) === 0;
    }

    /**
     * Merge POST and query parameters like PHP's default `request_order=GP`.
     *
     * POST values take precedence; cookies are not included.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        if ($this->inputCache === null) {
            $this->inputCache = $this->post() + $this->query();
        }
        return $this->inputCache;
    }

    /** Return cookies parsed from the request header. @return array<string, string> */
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