<?php

declare(strict_types=1);

namespace Volucer;

/**
 * RPC client for backends without a worker protocol channel.
 *
 * Reads the endpoint and token from CGI parameters or upstream headers, then
 * exposes the same KV, lock, and queue services as {@see Worker}. It connects
 * on the first call; each call is checksummed and has a configurable timeout.
 */
final class Rpc implements CallPlane
{
    /** The CGI parameter carrying the address to dial. */
    public const ENDPOINT_PARAM = 'VOLUCER_RPC';

    /** The CGI parameter carrying this backend's token. */
    public const TOKEN_PARAM = 'VOLUCER_RPC_TOKEN';

    /** The header an `upstream` origin is given the address in, as PHP sees it. */
    public const ENDPOINT_HEADER = 'HTTP_X_VOLUCER_RPC';

    /** The header an `upstream` origin is given the token in, as PHP sees it. */
    public const TOKEN_HEADER = 'HTTP_X_VOLUCER_RPC_TOKEN';

    /** The `unix:` prefix, the same convention [`Worker::UNIX_PREFIX`] holds. */
    public const UNIX_PREFIX = 'unix:';

    /** Default per-call deadline, matching `[rpc].call_timeout_seconds`. */
    public const DEFAULT_TIMEOUT = 10.0;

    /** How long to wait for the server to accept the connection. */
    private const CONNECT_TIMEOUT = 10.0;

    /** Maximum payload accepted from the server; checked before reading. */
    private const MAX_PAYLOAD_LEN = 64 * 1024 * 1024;

    /**
     * Where to dial, or null for "the deployment did not grant this backend anything".
     *
     * Null is a *permanent* answer for this instance: there is nothing to retry. A failed attempt to
     * reach a real address is different, and retried on every call — see [`Rpc::ensureStream()`].
     */
    private ?string $address;

    /** @var resource|null the connected socket, once there is one */
    private $stream = null;

    /** @var string the backend's identity, sent with every call */
    private string $token;

    /** How long one call may take, in seconds. */
    private float $timeout;

    /** The next call id, counting down from the top of the range. */
    private int $nextCallId = 0xFFFFFFFF;

    /** @var null|string why the last call failed, or null if it did not */
    private $lastCallError = null;

    /** Whether the last failed call may have taken effect on the server. */
    private bool $lastCallUnknown = false;

    /**
     * @param resource|null $in the socket to speak over, or null before the first connect
     */
    public function __construct(
        $in,
        string $token = '',
        ?string $address = null,
        float $timeout = self::DEFAULT_TIMEOUT,
    ) {
        if ($timeout <= 0.0) {
            throw new \InvalidArgumentException(
                'a call deadline has to be positive: zero would abandon every call the moment it was sent',
            );
        }

        $this->stream = $in;
        $this->token = $token;
        $this->address = $address;
        $this->timeout = $timeout;

        if (is_resource($in)) {
            stream_set_blocking($in, true);
            stream_set_timeout($in, (int) $timeout, (int) (fmod($timeout, 1.0) * 1_000_000));
        }
    }

    /**
     * Create a client using explicit values or the request environment.
     *
     * Connection is deferred until the first call; broken connections are retried.
     */
    public static function connect(
        ?string $address = null,
        ?string $token = null,
        float $timeout = self::DEFAULT_TIMEOUT,
    ): self {
        return new self(
            null,
            $token ?? self::environment(self::TOKEN_PARAM, self::TOKEN_HEADER) ?? '',
            $address ?? self::environment(self::ENDPOINT_PARAM, self::ENDPOINT_HEADER),
            $timeout,
        );
    }

    /**
     * Resolve a non-empty CGI parameter or header, then fall back to the environment.
     *
     * Values are trimmed; blank values return null.
     */
    private static function environment(string $param, string $header): ?string
    {
        foreach ([$param, $header] as $name) {
            $value = $_SERVER[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach ([$param, $header] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Return the configured endpoint, or null if no services were granted. */
    public function address(): ?string
    {
        return $this->address;
    }

    /** Return the path of a `unix:` address, or null for TCP. */
    public static function unixPath(string $address): ?string
    {
        return Worker::unixPath($address);
    }

    // ------------------------------------------------------------------ handles

    /** Return a handle to one of the backend's configured KV storages. */
    public function kv(string $storage = 'default'): Kv
    {
        return new Kv($this, $storage);
    }

    /** Return a handle to the server's resource locks. */
    public function lock(): Lock
    {
        return new Lock($this);
    }

    /**
     * Return a producer handle for a configured queue.
     *
     * @param string $pipeline the name from the `[jobs.pipelines.<name>]` table.
     */
    public function queue(string $pipeline): Jobs
    {
        return new Jobs($this, $pipeline);
    }

    // -------------------------------------------------------------------- calls

    /**
     * Call a server service.
     *
     * @param string $name `"<namespace>.<verb>"`, e.g. `kv.get`.
     * @param array<string, mixed> $params method-specific arguments.
     * @return mixed the result, or null on refusal; see {@see Rpc::lastCallError()}.
     * @throws ProtocolException if the connection failed, timed out, or a frame arrived out of order.
     */
    public function call(string $name, array $params = []): mixed
    {
        $this->lastCallError = null;
        $this->lastCallUnknown = false;

        if (!$this->ensureStream()) {
            // Nothing was sent, so this is a definite failure, not an unknown outcome.
            return null;
        }

        $id = $this->nextCallId();
        $call = ['name' => $name, 'params' => $params];
        if ($this->token !== '') {
            // Omit an absent token so the server can distinguish missing configuration.
            $call['token'] = $this->token;
        }

        $payload = json_encode($call, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->send(new Frame(MessageType::CALL, $id, $payload), $name);

        return $this->awaitReturn($id, $name);
    }

    /** Return the reason the last call failed, or null if it succeeded. */
    public function lastCallError(): ?string
    {
        return $this->lastCallError;
    }

    /** Whether the failed call may have been served before its connection failed. */
    public function lastCallWasUnknown(): bool
    {
        return $this->lastCallUnknown;
    }

    /** Close the connection; the next call reconnects. */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    // ----------------------------------------------------------------- transport

    /**
     * Ensure a connection, retrying failures when an endpoint is configured.
     *
     * Missing configuration is reported without attempting a connection.
     */
    private function ensureStream(): bool
    {
        if (is_resource($this->stream)) {
            return true;
        }

        if ($this->address === null) {
            $this->lastCallError = sprintf(
                'this request carries no %s, so this backend was granted no services in the deployment: '
                . 'add the ones it needs to its `services` list, and add an [rpc] table if the '
                . 'deployment has none',
                self::ENDPOINT_PARAM,
            );

            return false;
        }

        $unix = self::unixPath($this->address);

        // Report connection failures through lastCallError() instead of PHP warnings.
        $stream = @stream_socket_client(
            $unix === null ? 'tcp://' . $this->address : 'unix://' . $unix,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            // TCP options do not apply to Unix sockets.
            $unix === null ? self::socketContext() : null,
        );

        if ($stream === false) {
            $this->lastCallError = sprintf(
                'the call plane at %s could not be reached: %s (%d)',
                $this->address,
                $errstr,
                $errno,
            );

            return false;
        }

        stream_set_blocking($stream, true);
        stream_set_timeout(
            $stream,
            (int) $this->timeout,
            (int) (fmod($this->timeout, 1.0) * 1_000_000),
        );
        $this->stream = $stream;
        return true;
    }

    /**
     * Create a TCP context with Nagle disabled.
     *
     * @return null|resource
     */
    private static function socketContext()
    {
        $context = stream_context_create(['socket' => ['tcp_nodelay' => true]]);

        return $context === false ? null : $context;
    }

    /**
     * Write one frame, in full.
     */
    private function send(Frame $frame, string $name): void
    {
        $bytes = $frame->encode();

        while ($bytes !== '') {
            $written = @fwrite($this->stream, $bytes);
            if ($written === false || $written === 0) {
                // The call may have been served before the connection broke, so this is the
                // undecided case rather than a definite failure: retrying it could duplicate the
                // write the caller asked for.
                $this->drop();
                $this->lastCallUnknown = true;
                throw new ProtocolException($this->lastCallError = sprintf(
                    'call "%s": the connection to the call plane broke while the call was being sent, '
                    . 'so the server may or may not have served it',
                    $name,
                ));
            }
            $bytes = substr($bytes, $written);
        }

        if (@fflush($this->stream) === false) {
            $this->drop();
            $this->lastCallUnknown = true;
            throw new ProtocolException($this->lastCallError = sprintf(
                'call "%s": the connection could not be flushed, so the server may or may not have '
                . 'served the call',
                $name,
            ));
        }
    }

    /**
     * Read and validate the matching RETURN frame.
     *
     * @return mixed
     */
    private function awaitReturn(int $id, string $name): mixed
    {
        while (true) {
            $header = $this->readHeader($name);

            if ($header->payloadLen > self::MAX_PAYLOAD_LEN) {
                $this->drop();
                throw new ProtocolException($this->lastCallError = sprintf(
                    'call "%s": the answer declares %d payload bytes, which is past the %d this client '
                    . 'will read',
                    $name,
                    $header->payloadLen,
                    self::MAX_PAYLOAD_LEN,
                ));
            }

            $payload = $header->payloadLen === 0 ? '' : $this->readBytes($header->payloadLen, $name);

            if (!$header->declaresNoChecksum() && Frame::crc32c($payload) !== $header->checksum) {
                $this->drop();
                throw new ProtocolException($this->lastCallError = sprintf(
                    'call "%s": the answer failed its checksum, so the stream can no longer be trusted',
                    $name,
                ));
            }

            if ($header->messageType !== MessageType::RETURN) {
                $this->drop();
                throw new ProtocolException($this->lastCallError = sprintf(
                    'call "%s": an [rpc] connection carries only RETURN frames from the server, and a '
                    . '%s arrived',
                    $name,
                    $header->typeName(),
                ));
            }

            if ($header->requestId !== $id) {
                $this->drop();
                throw new ProtocolException($this->lastCallError = sprintf(
                    'call "%s": expected the answer to 0x%08X but the server answered 0x%08X',
                    $name,
                    $id,
                    $header->requestId,
                ));
            }

            return $this->acceptReturn($payload, $name);
        }
    }

    /**
     * Turn a `RETURN` payload into a result, or into a recorded error.
     *
     * @return mixed
     */
    private function acceptReturn(string $payload, string $name): mixed
    {
        $answer = $this->decodeJson($payload, $name);

        if (($answer['ok'] ?? true) === true) {
            return $answer['result'] ?? null;
        }

        $this->lastCallError = (string) ($answer['error'] ?? 'the server refused the call without saying why');
        $this->lastCallUnknown = ($answer['unknown'] ?? false) === true;

        return null;
    }

    /** Read one frame header, dropping the connection if it is malformed. */
    private function readHeader(string $name): Header
    {
        $bytes = $this->readBytes(Header::HEADER_LEN, $name);

        try {
            return Header::decode($bytes);
        } catch (ProtocolException $exception) {
            // Framing cannot be recovered after a malformed header.
            $this->drop();
            $this->lastCallUnknown = true;
            throw new ProtocolException($this->lastCallError = sprintf(
                'call "%s": the answer\'s header is not a valid frame header (%s)',
                $name,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * Read exactly `$length` bytes, dropping the connection on EOF or timeout.
     *
     * A failed read consumes part of a frame, so the connection cannot be reused.
     */
    private function readBytes(int $length, string $name): string
    {
        $bytes = @stream_get_contents($this->stream, $length);
        $meta = is_resource($this->stream) ? stream_get_meta_data($this->stream) : ['timed_out' => false];

        if ($bytes !== false && strlen($bytes) === $length) {
            return $bytes;
        }

        $this->drop();

        if (($meta['timed_out'] ?? false) === true) {
            $this->lastCallUnknown = true;
            throw new ProtocolException($this->lastCallError = sprintf(
                'call "%s" got no answer within %.1f s; the server may still have served it, and the '
                . 'connection has been dropped. A call that legitimately takes longer needs a longer '
                . 'deadline — Rpc::connect(timeout: …) — and [rpc].call_timeout_seconds governs only '
                . 'how long the server will spend writing',
                $name,
                $this->timeout,
            ));
        }

        $this->lastCallUnknown = true;
        throw new ProtocolException($this->lastCallError = sprintf(
            'call "%s": the connection to the call plane ended after %d of %d expected bytes',
            $name,
            $bytes === false ? 0 : strlen($bytes),
            $length,
        ));
    }

    /**
     * Forget the connection, so the next call reconnects.
     */
    private function drop(): void
    {
        $this->close();
    }

    /** Allocate a call id from the range reserved for calls. */
    private function nextCallId(): int
    {
        $id = $this->nextCallId;
        $this->nextCallId = $id <= 0x80000001 ? 0xFFFFFFFF : $id - 1;

        return $id;
    }

    /**
     * Decode a payload as a JSON object, or explain why it is not one.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $payload, string $name): array
    {
        if ($payload === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->drop();
            throw new ProtocolException($this->lastCallError = sprintf(
                'call "%s": the answer was not valid JSON: %s',
                $name,
                $exception->getMessage(),
            ));
        }

        if (!is_array($decoded)) {
            $this->drop();
            throw new ProtocolException($this->lastCallError = sprintf(
                'call "%s": the answer was not a JSON object',
                $name,
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
