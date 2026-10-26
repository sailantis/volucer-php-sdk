<?php

declare(strict_types=1);

namespace Volucer;

/**
 * The call plane for an application that has no worker channel.
 *
 * A [`Worker`] reaches `kv`, `lock` and `jobs` on the stream that carries its requests. A FastCGI
 * application has no such stream — it exists for the duration of one request and can only answer the
 * frame it was handed — so volucer serves the same namespaces on a socket of its own, `[rpc]`, which
 * this class dials. The names are then used exactly as a worker uses them:
 *
 * ```php
 * $rpc = Rpc::connect();
 *
 * $rpc->kv('cache')->set('greeting', 'hello');
 * $id = $rpc->lock()->acquire('pdf:create');      // same lease semantics as Worker::lock()
 * $rpc->queue('mail')->push('{"to":"a@b"}');
 * ```
 *
 * ## Nothing here is configured, and that is the point
 *
 * The address and the backend's identity are **in the request's environment**, put there by volucer
 * — `VOLUCER_RPC` and `VOLUCER_RPC_TOKEN` as CGI parameters, or `X-Volucer-Rpc` and
 * `X-Volucer-Rpc-Token` as headers for an `upstream` origin. One deployment states the address once,
 * in one file, and both sides read it; an application never has a second copy to keep in step.
 *
 * A backend whose `services` list is empty is handed neither, which is why [`Rpc::connect()`] does not
 * throw when they are missing: the application gets a plane that refuses every call with a message
 * naming the missing variable. A request that never touches a store therefore still works, and the
 * one that does gets an error pointing at the deployment rather than at this class.
 *
 * ## The wire is the worker protocol's, minus the handshake
 *
 * Same [`Header`], same [`MessageType::CALL`]/[`MessageType::RETURN`], same CRC-32C. What a worker
 * establishes once in `HELLO`/`HELLO_ACK` — version, checksum policy, process identity — would be paid
 * for on every request by a CGI process, so instead **each call carries the token**. That also means a
 * grant withdrawn by a reload takes effect on the next call rather than at the next reconnect.
 *
 * One consequence worth knowing: there is no `HELLO`, so there is no offer to skip the checksum, so
 * every frame this class writes is checksummed.
 *
 * ## The deadline is here, unlike a worker's channel
 *
 * [`Worker::call()`] blocks with no timeout of its own, because the protocol guarantees an answer and
 * a timeout there would have to be `stream_select`, which is meaningless on a Windows pipe. This
 * class is the opposite case: it reads a **socket**, so `stream_set_timeout` works, and there is
 * something to time out — a server that has stopped reading, a call whose answer was lost to a
 * network hiccup. A blocking read with no deadline in a CGI process is a request that hangs until
 * PHP's own `max_execution_time` kills it, and that failure names the wrong thing.
 *
 * The default matches `[rpc].call_timeout_seconds` in the default deployment, and **a call that
 * legitimately takes longer needs a longer deadline**: `Lock::acquire($resource, $ttl, $wait)` may
 * wait for `$wait` microseconds, so a caller asking for more than the timeout must say so —
 * `Rpc::connect(timeout: 30.0)`.
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

    /**
     * The default per-call deadline, in seconds.
     *
     * The same number as `[rpc].call_timeout_seconds` defaults to, so a deployment that configured
     * nothing does not have the two ends disagreeing about how long a call may take. A deployment that
     * raised the server's value has to raise this one too — the server's is a *write* deadline and
     * does not govern how long a handler may run.
     */
    public const DEFAULT_TIMEOUT = 10.0;

    /** How long to wait for the server to accept the connection. */
    private const CONNECT_TIMEOUT = 10.0;

    /**
     * The largest payload this client will read, matching `MAX_PAYLOAD_LEN` in the server.
     *
     * Checked against the header *before* reading, so a corrupt or hostile length cannot make this
     * process allocate gigabytes it will never fill.
     */
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
     * Dial the call plane this request was told about.
     *
     * Reads `VOLUCER_RPC`/`VOLUCER_RPC_TOKEN` from `$_SERVER`, falling back to the
     * `HTTP_X_VOLUCER_RPC*` header form an `upstream` backend is handed. A `$token` or `$address`
     * argument overrides the environment, which is what a test and a non-CGI caller (a CLI script, a
     * queue consumer) need.
     *
     * **No I/O happens here.** A request that never touches a store should not pay a `connect`, and a
     * server that is briefly down should not fail a request that had no intention of calling it. The
     * first [`Rpc::call()`] connects, and every call after a broken connection reconnects.
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
     * The first non-empty of a CGI parameter and a header name, or null.
     *
     * `$_SERVER` rather than `getenv()` first, because under FastCGI the value volucer injects is a
     * CGI parameter and never reaches the process environment; `getenv()` is the fallback for a CLI
     * script that was started with `VOLUCER_RPC=…`, which is what `volucer rpc`-style tooling and a
     * hand-run test do.
     *
     * Whitespace is trimmed to nothing, the same rule [`Worker::socketAddress()`] applies to
     * `VOLUCER_WORKER_SOCKET`: `VOLUCER_RPC=` and `VOLUCER_RPC= ` are both what a shell produces while
     * somebody is debugging, and connecting to `tcp:// ` would be a confusing failure rather than an
     * obvious one.
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

    /**
     * The address this plane dials, or null when the deployment granted this backend nothing.
     *
     * For a diagnostic — "which call plane is this application talking to?" is the first question
     * when a store works in one backend and not another. The address is in the request's environment
     * either way, so this publishes nothing that a `var_dump($_SERVER)` would not.
     */
    public function address(): ?string
    {
        return $this->address;
    }

    /**
     * The filesystem path of a `unix:` address, or null for a TCP one.
     *
     * Delegated rather than reimplemented: the prefix is volucer's own convention and appears in four
     * other places already, and a second spelling of it here would be a fifth thing to keep true.
     */
    public static function unixPath(string $address): ?string
    {
        return Worker::unixPath($address);
    }

    // ------------------------------------------------------------------ handles

    /**
     * A handle to one of the server's key-value storages.
     *
     * The same handle [`Worker::kv()`] returns, over the same store — the storage must be one this
     * backend was granted in `services`, and a call naming another is refused by the server with the
     * storages that *are* reachable.
     */
    public function kv(string $storage = 'default'): Kv
    {
        return new Kv($this, $storage);
    }

    /**
     * A handle to the server's resource locks.
     *
     * Read [`Lock`]'s class documentation before relying on one: the lease semantics, and the fact
     * that a lease is not a fencing token, apply here exactly as they do to a worker.
     */
    public function lock(): Lock
    {
        return new Lock($this);
    }

    /**
     * A handle to one of the server's queues, from the producer's side.
     *
     * @param string $pipeline the name from the `[jobs.pipelines.<name>]` table.
     */
    public function queue(string $pipeline): Jobs
    {
        return new Jobs($this, $pipeline);
    }

    // -------------------------------------------------------------------- calls

    /**
     * Ask the server to do something.
     *
     * Unlike [`Worker::call()`] this is callable at any time: there is no request whose answer it
     * could steal, because there is no request on this connection. Each call is one round trip.
     *
     * @param string $name `"<namespace>.<verb>"`, e.g. `kv.get`.
     * @param array<string, mixed> $params method-specific arguments.
     *
     * @return mixed the call's result, or null when the server refused it; see {@see Rpc::lastCallError()}.
     *
     * @throws ProtocolException if the connection failed, timed out, or a frame arrived out of order.
     */
    public function call(string $name, array $params = []): mixed
    {
        $this->lastCallError = null;
        $this->lastCallUnknown = false;

        if (!$this->ensureStream()) {
            // Recorded rather than thrown, so that this behaves like every other refusal: `Kv::ask()`
            // turns it into an exception naming the store and the reason, and a caller that wants to
            // treat a cache failure as a miss can still catch it. Nothing was sent, so the call
            // certainly did not happen — `unknown` stays false.
            return null;
        }

        $id = $this->nextCallId();
        $call = ['name' => $name, 'params' => $params];
        if ($this->token !== '') {
            // **Omitted rather than sent empty when there is no token.** The server tells the two
            // apart on purpose: "carried no token" is a deployment that granted this backend nothing,
            // and "carried a token no backend was issued" is an application reading the wrong
            // variable. An empty string would make the second message the one an operator saw.
            $call['token'] = $this->token;
        }

        $payload = json_encode($call, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->send(new Frame(MessageType::CALL, $id, $payload), $name);

        return $this->awaitReturn($id, $name);
    }

    /**
     * Why the last {@see Rpc::call()} did not return a result, or null if it did.
     *
     * The same distinction [`Worker::lastCallError()`] draws: `kv.get` on a missing key returns null
     * and *succeeded*, and a caller that could not tell the two apart would treat a cache miss as a
     * failure.
     */
    public function lastCallError(): ?string
    {
        return $this->lastCallError;
    }

    /**
     * Whether the call that failed may have taken effect.
     *
     * True for a connection that broke or timed out **after the call was written** — the server may
     * have served it and lost the answer. False for a call that was never sent, which is the whole
     * reason [`Rpc::call()`] records a missing address instead of throwing: "the deployment granted
     * this backend nothing" is a definite non-event.
     */
    public function lastCallWasUnknown(): bool
    {
        return $this->lastCallUnknown;
    }

    /**
     * Close the connection, if there is one.
     *
     * Not needed before the process exits — a CGI child's descriptors go with it — but a caller that
     * keeps an `Rpc` in a static, or a long-running CLI script, may want the descriptor back. The next
     * call reconnects.
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    // ----------------------------------------------------------------- transport

    /**
     * Make sure there is a connection, connecting if there is not one.
     *
     * **Retried on every call rather than remembered as failed.** A failure here is the server being
     * briefly absent — a restart, a socket not yet bound — and a plane that gave up for the life of
     * the process would turn a two-second gap into a permanently broken application. What is *not*
     * retried is an address the deployment never provided: there is nothing to connect to, and the
     * message says which variable is missing.
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

        // `@` because the warning duplicates the message recorded below, and because a connect
        // failure is an ordinary condition here rather than a PHP diagnostic.
        $stream = @stream_socket_client(
            $unix === null ? 'tcp://' . $this->address : 'unix://' . $unix,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            // A context only for TCP: `tcp_nodelay` is `ENOPROTOOPT` on an `AF_UNIX` socket, and
            // `Worker::connect()` makes the same distinction for the same reason.
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
     * Disable Nagle, for the reason [`Worker`] does: the protocol is a ping-pong of small frames, and
     * an option that turns 39 ms per request into 0.06 ms is not a micro-optimisation.
     *
     * A *context* option rather than a call after connecting, because PHP has no portable
     * post-connect equivalent without `ext-sockets`, which this package deliberately does not
     * require.
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
     * Read frames until the answer to `$id` arrives.
     *
     * A loop rather than a single read, for the same reason [`Worker::awaitReturn()`] is one: a frame
     * that is not this call's answer is possible on the wire, and treating it as a desynchronisation
     * would turn a harmless extra frame into a broken connection. What can arrive here is narrow — the
     * listener answers `CALL` and `PING` and refuses anything else by name — so a `RETURN` for a
     * different id is the only case worth rejecting out loud.
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

    /**
     * Read one frame header.
     *
     * A timeout here is reported as a timeout, not as a closed connection, and the distinction is the
     * reason [`Rpc::readBytes()`] exists: `stream_get_contents()` returns `''` for both, and telling an
     * operator "the server closed the connection" when it was merely slow sends them to the wrong
     * process.
     */
    private function readHeader(string $name): Header
    {
        $bytes = $this->readBytes(Header::HEADER_LEN, $name);

        try {
            return Header::decode($bytes);
        } catch (ProtocolException $exception) {
            // The framing is gone, not merely this frame: a header that does not decode means the
            // stream's boundaries are unknown from here on, so the connection goes with it.
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
     * Read exactly `$length` bytes, or explain why it could not.
     *
     * `stream_get_contents($stream, $n)` blocks until it has `n` bytes, reaches EOF, or the stream's
     * deadline expires — handling partial reads internally, which is why this class has no `fread`
     * loop. The three outcomes are told apart by the stream's metadata afterwards.
     *
     * **Every failure here drops the connection**, and that is not caution. A short read leaves part
     * of a frame consumed and discarded, so the stream's framing is gone; a caller that kept the
     * handle would read the rest of that frame as the start of the next one.
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

    /**
     * The next call id, counting down from the top of the range.
     *
     * Counted down for the same reason [`Worker`] counts down: the server decides what a frame is by
     * which half of the range its id falls in, so a call that looked like a request would be refused
     * by the framing layer. The wrap is four billion calls away.
     */
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
