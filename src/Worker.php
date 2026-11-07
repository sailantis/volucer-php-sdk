<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Persistent worker for the Volucer protocol.
 *
 * Protocol frames use stdout; application output is captured and errors go to stderr.
 * The worker exits gracefully when the server closes stdin.
 */
final class Worker implements CallPlane
{
    /** The largest body slice sent in one frame, matching MAX_BODY_CHUNK in Rust. */
    public const MAX_BODY_CHUNK = 65536;

    /** The request id reserved for unsolicited frames. */
    public const UNSOLICITED = 0;

    /**
     * The package this SDK is, as Composer knows it.
     *
     * Used to look the build up by name: asking Composer about the *root* package
     * would return the consuming application instead, and reporting the host's
     * build under the worker's own name is worse than reporting nothing.
     */
    public const PACKAGE = 'sailantis/volucer-worker';

    /**
     * Return this SDK's installed version and commit reference, or `unknown`.
     *
     * Values come from Composer when available, so development checkouts are
     * identified without maintaining a version constant.
     */
    public static function build(): string
    {
        [$version, $reference] = self::installed();

        if ($version === null && $reference === null) {
            return 'unknown';
        }

        // A published release: the version is the build, and the commit pins it.
        if ($version !== null && !str_starts_with($version, 'dev-')) {
            return $reference === null ? $version : $version . '+' . self::short($reference);
        }

        // A development checkout: there is no meaningful version, so the commit
        // *is* the build. It is also the reference a consuming application's
        // composer.lock pins, which is what makes it the answer worth printing.
        return $reference === null ? 'unknown' : self::short($reference);
    }

    /** The seven characters that identify a build in a log line. */
    private static function short(string $reference): string
    {
        return substr($reference, 0, 7);
    }

    /**
     * Return this package's Composer version and reference, or nulls if unavailable.
     *
     * @return array{0: ?string, 1: ?string} version, then commit reference
     */
    private static function installed(): array
    {
        $class = 'Composer\\InstalledVersions';
        if (!class_exists($class)) {
            return [null, null];
        }

        try {
            if ($class::isInstalled(self::PACKAGE)) {
                return [
                    self::text($class::getVersion(self::PACKAGE)),
                    self::text($class::getReference(self::PACKAGE)),
                ];
            }

            // The package's own checkout: it is installed, but as the root.
            $root = $class::getRootPackage();
        } catch (\Throwable) {
            return [null, null];
        }

        if (!is_array($root) || ($root['name'] ?? null) !== self::PACKAGE) {
            return [null, null];
        }

        return [
            self::text($root['pretty_version'] ?? $root['version'] ?? null),
            self::text($root['reference'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private int $requestId = self::UNSOLICITED;

    /**
     * The parsed form fields for the request being served, and its uploaded files.
     *
     * Held on the worker rather than threaded through `buildRequest` as more parameters,
     * because the two are per-request state set once and read from three places — the
     * superglobals, `buildRequest`, and the `finally` that clears them.
     *
     * @var array<string, mixed>
     */
    private array $currentPost = [];

    /** @var array<string, mixed> */
    private array $currentFiles = [];

    /** Whether this request had its CGI superglobals installed, and so must clear them. */
    private bool $installingGlobals = false;

    /** Whether CGI superglobals are installed for HTTP requests (enabled by default). */
    private bool $cgiGlobals = true;

    /** Whether the current request still needs a response from the shutdown handler. */
    private bool $requestUnanswered = false;

    /** Whether the process-wide shutdown responder has been registered. */
    private bool $shutdownResponderRegistered = false;

    /** The output-buffer nesting depth we found when the capture started. */
    private int $captureLevel = 0;

    /**
     * Disable CGI superglobal installation for applications that use {@see Request} instead.
     *
     * The default is enabled for compatibility with conventional PHP applications.
     */
    public function withoutCgiGlobals(): static
    {
        $this->cgiGlobals = false;

        return $this;
    }

    /** Whether the CGI superglobals are installed. See {@see Worker::withoutCgiGlobals()}. */
    public function installsCgiGlobals(): bool
    {
        return $this->cgiGlobals;
    }
    private bool $keepAlive = true;

    private bool $stopping = false;

    /**
     * Whether frames are written with a checksum.
     *
     * Set once, by the handshake, from the agreement the server offered in its `HELLO`. Read
     * on both sides of the codec: [`Worker::send`] writes frames under it, and
     * [`Worker::readPayload`] uses it to decide whether a frame may declare that it has no
     * checksum.
     */
    private bool $checksum = true;

    /**
     * What answers a `CALL` from the server.
     *
     * A callable taking the decoded call as an array — `name`, `params` — and returning either a
     * value to send as the result, or an array of `['ok' => false, 'error' => '...']` to refuse it.
     *
     * **Null means a refusal, not silence.** A `CALL` that is not answered leaves the server waiting
     * until its own deadline, and in the server's pool a deadline is a kill — so a worker with no
     * handler must still write a `RETURN`, saying so. That is the whole reason this is not simply an
     * `if ($handler === null) { continue; }`.
     *
     * @var null|callable(array<string, mixed>): mixed
     */
    private $callHandler = null;

    /**
     * The next call id, counting down from the top of the range.
     *
     * Allocated here rather than by the server, because the direction is worker → server: this worker
     * asks and the server answers, so the worker owns the id space. Counted down from `0xFFFFFFFF` so
     * that it can never collide with a request id, which counts up from 1.
     */
    private int $nextCallId = 0xFFFFFFFF;

    /**
     * Why the last call failed, or null if it did not.
     *
     * @var null|string
     */
    private $lastCallError = null;

    /**
     * Whether the last failed call may have taken effect on the server.
     */
    private bool $lastCallUnknown = false;

    /**
     * What answers a `JOB` from the server, in consumer mode.
     *
     * A callable taking the decoded job and returning `null` (acknowledge), `false` (requeue) or a
     * string (reject with that reason). Set by [`Worker::consume`], and the reason this class owns the
     * consumer loop rather than a separate `Consumer` class: the loop needs the frame codec, the
     * capture machinery *not* to run, and the control handler — all of which are here, and a second
     * class would either duplicate them or reach into this one's privates.
     *
     * @var null|callable(Job, Worker): mixed
     */
    private $jobHandler = null;

    /**
     * What answers a `REQUEST` while this worker is serving gRPC, in gRPC mode.
     *
     * Set by [`Worker::grpc`], and held for the same reason `jobHandler` is rather than being passed
     * down through `run`: the loop that reads frames is this class's, and a mode that had to thread a
     * handler through the request path would make every other mode's signature carry it.
     *
     * @var null|callable(Request, Worker): mixed
     */
    private $grpcHandler = null;

    /**
     * What answers a `REQUEST` that is *not* a gRPC call, in `run()` mode.
     *
     * Set by [`Worker::run`]. A field rather than a parameter of the serve path, for the same reason
     * `jobHandler` is: the loop that reads frames is this class's, and the request path now has two
     * handlers — one per protocol — so the one that used to arrive as an argument could not stay one.
     *
     * @var null|callable(Request, Worker): mixed
     */
    private $httpHandler = null;

    /**
     * @param resource $in  the server's end: frames to read
     * @param resource $out stdout: frames to write
     * @param resource $err stderr: diagnostics, never part of the protocol
     */
    public function __construct($in, $out, $err)
    {
        $this->in = $in;
        $this->out = $out;
        $this->err = $err;

        // Register early so framework shutdown output is captured before the response is finalized.
        $this->registerShutdownResponder();
    }

    /**
     * Create a worker bound to the real stdio.
     *
     * `php://stdin` and `php://stdout` are opened as byte handles in binary
     * mode. Diagnostics go to the `STDERR` constant, which the protocol leaves
     * entirely free.
     *
     * `display_errors` is forced to `stderr` here so that a warning or notice
     * raised at any point during the worker's life cannot be printed into the
     * frame stream. That setting is rule 1's second half.
     */
    public static function create($in = null, $out = null): self
    {
        ini_set('display_errors', 'stderr');

        if ($in === null) {
            $in = fopen('php://stdin', 'rb');
        }
        if ($out === null) {
            $out = fopen('php://stdout', 'wb');
        }

        if ($in === false || $out === false) {
            throw new ProtocolException('could not open the stdio pipe');
        }

        stream_set_blocking($in, true);
        stream_set_blocking($out, true);

        /** @var resource $err */
        $err = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');

        return new self($in, $out, $err);
    }

    /**
     * The environment variable a server sets to ask for the socket transport.
     *
     * Its value is the `host:port` the server is already listening on.
     */
    public const SOCKET_ENV = 'VOLUCER_WORKER_SOCKET';

    /**
     * The prefix volucer's configuration uses for a unix socket address.
     *
     * The same string `Supervisor::UNIX_PREFIX` holds, and deliberately a separate constant:
     * the two are read in different directions (a worker dials, a supervisor listens) and one
     * of them moving without the other is a bug worth being able to see.
     */
    public const UNIX_PREFIX = 'unix:';

    /**
     * How long to wait for the server to accept the connection.
     *
     * Generous on purpose: the server binds its listener *before* spawning the worker, so
     * the connection is already waiting when this runs. The timeout is here to turn "the
     * server is not there" into an error rather than a hang.
     */
    private const CONNECT_TIMEOUT = 10.0;

    /** Disable Nagle's algorithm for the small request/response frames. */
    private const TCP_NODELAY = true;

    /**
     * Connect using the configured socket, or use stdio when none is configured.
     *
     * A failed socket connection exits with an error; it never silently falls back to stdio.
     */
    public static function connect(): self
    {
        $address = self::socketAddress();

        if ($address === null) {
            return self::create();
        }

        $unix = self::unixPath($address);

        // Suppress the stream warning because the failure is reported below; TCP options
        // are only applied to TCP transports.
        $stream = @stream_socket_client(
            $unix === null ? 'tcp://' . $address : 'unix://' . $unix,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $unix === null ? self::socketContext() : null,
        );

        if ($stream === false) {
            fwrite(
                STDERR,
                'worker: cannot reach ' . $address . ' (' . self::SOCKET_ENV . '): '
                . $errstr . ' (' . $errno . ')' . PHP_EOL,
            );
            exit(1);
        }

        return self::create($stream, $stream);
    }

    /** Return the path for a `unix:` address, or null for TCP. */
    public static function unixPath(string $address): ?string
    {
        if (!str_starts_with($address, self::UNIX_PREFIX)) {
            return null;
        }

        return substr($address, strlen(self::UNIX_PREFIX));
    }

    /**
     * Create the TCP socket context.
     *
     * @return null|resource
     */
    private static function socketContext()
    {
        $context = stream_context_create([
            'socket' => ['tcp_nodelay' => self::TCP_NODELAY],
        ]);

        return $context === false ? null : $context;
    }

    /** Return the configured socket address, or null when stdio should be used. */
    public static function socketAddress(): ?string
    {
        $address = getenv(self::SOCKET_ENV);

        if (!is_string($address)) {
            return null;
        }

        // Whitespace too, not just the empty string: `VOLUCER_WORKER_SOCKET= ` is what a
        // shell produces from a stray space, and connecting to `tcp:// ` would be a
        // confusing failure rather than the obvious one.
        $address = trim($address);

        return $address === '' ? null : $address;
    }

    /**
     * Serve requests until the server closes stdin.
     *
     * The handler may return a {@see Response}, a string body, or null to use
     * captured output as the response body.
     *
     * @param callable(Request, self): (Response|string|null) $handler
     */
    public function run(callable $handler): void
    {
        $this->httpHandler = $handler;

        $this->handshake();

        while (!$this->stopping) {
            $header = $this->readHeader();

            if ($header === null) {
                // Clean EOF: the server closed our stdin, which is how a worker
                // is asked to stop. Not an error, and not worth a log line.
                break;
            }

            $payload = $this->readPayload($header);

            switch ($header->messageType) {
                case MessageType::REQUEST:
                    $this->serve($header, $payload);
                    break;

                case MessageType::JOB:
                    // A job on a worker that was started as a request worker. Named rather than
                    // ignored, because the two exchanges differ in the one thing an application
                    // cannot discover later: whether its own state is reset between units of work.
                    $this->diagnose(
                        'the server sent a JOB to a worker that serves requests; this worker is '
                        . 'configured for the wrong mode, and the job is being refused'
                    );
                    $this->refuseJob($header, $payload);
                    break;

                case MessageType::PING:
                    $this->send(new Frame(MessageType::PONG, self::UNSOLICITED));
                    break;

                case MessageType::CONTROL:
                    $this->handleControl($payload);
                    break;

                case MessageType::CALL:
                    $this->answerCall($header, $payload);
                    break;

                case MessageType::LOG:
                case MessageType::METRIC:
                case MessageType::EVENT:
                    // The server should not send these, but a peer that echoes
                    // telemetry back must not derail the loop.
                    $this->diagnose('ignoring an unexpected ' . $header->typeName() . ' from the server');
                    break;

                default:
                    throw new ProtocolException(
                        'the server sent a ' . $header->typeName() . ', which a worker cannot serve'
                    );
            }
        }
    }

    /**
     * Handle jobs until the server closes the connection.
     *
     * Return null to acknowledge, false to requeue, or a string to reject.
     * Exceptions are logged and requeue the job; handler output is sent as a log.
     *
     * @param callable(Job, self): (string|false|null) $handler
     */
    public function consume(callable $handler): void
    {
        $this->jobHandler = $handler;
        $this->handshake();

        while (!$this->stopping) {
            $header = $this->readHeader();

            if ($header === null) {
                break;
            }

            $payload = $this->readPayload($header);

            switch ($header->messageType) {
                case MessageType::JOB:
                    $this->handleJob($header, $payload);
                    break;

                case MessageType::PING:
                    $this->send(new Frame(MessageType::PONG, self::UNSOLICITED));
                    break;

                case MessageType::CONTROL:
                    $this->handleControl($payload);
                    break;

                case MessageType::CALL:
                    $this->answerCall($header, $payload);
                    break;

                case MessageType::LOG:
                case MessageType::METRIC:
                case MessageType::EVENT:
                    $this->diagnose('ignoring an unexpected ' . $header->typeName() . ' from the server');
                    break;

                case MessageType::REQUEST:
                    // Report the mode mismatch so the server can fail the request without a timeout.
                    $this->diagnose(
                        'the server sent a REQUEST to a worker that consumes jobs; this worker is '
                        . 'configured for the wrong mode'
                    );
                    $this->sendJson(MessageType::ERROR, $header->requestId, [
                        'message' => 'this worker consumes jobs, not requests',
                        'request_id' => $header->requestId,
                        'recoverable' => false,
                    ]);
                    break;

                default:
                    throw new ProtocolException(
                        'the server sent a ' . $header->typeName() . ', which a consumer cannot serve'
                    );
            }
        }
    }

    /** Read a job, invoke its handler, and send the resulting acknowledgement. */
    private function handleJob(Header $header, string $payload): void
    {
        $this->requestId = $header->requestId;
        $this->keepAlive = true;

        $metadata = $this->decodeJson($payload, 'a JOB header');
        $body = $this->readRequestBody($header->requestId, $this->optionalIntField($metadata, 'body_len'));

        $job = new Job(
            pipeline: $this->stringField($metadata, 'pipeline'),
            id: $this->stringField($metadata, 'id'),
            task: $this->stringField($metadata, 'task'),
            headers: $this->headersToMap($this->pairsField($metadata, 'headers')),
            attempts: $this->intField($metadata, 'attempts'),
            payload: $body,
        );

        $started = hrtime(true);

        // Jobs have no response body, so captured application output is logged.
        $this->beginCapture();
        try {
            /** @var mixed $result */
            $result = ($this->jobHandler)($job, $this);
            $outcome = match (true) {
                $result === false => 'requeue',
                is_string($result) => 'reject',
                default => 'ack',
            };
            $reason = is_string($result) ? $result : null;
        } catch (\Throwable $exception) {
            $this->diagnose(sprintf(
                'uncaught %s in job %s: %s in %s:%d',
                $exception::class,
                $job->id,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            // Preserve the job when its handler fails.
            $outcome = 'requeue';
            $reason = $exception->getMessage();
        } finally {
            $emitted = $this->endCapture();
            if ($emitted !== '') {
                $this->emitLog($emitted, 'info');
            }
        }

        $answer = [
            'outcome' => $outcome,
            'peak_memory_bytes' => $this->peakMemoryBytes(),
            'duration_us' => (int) ((hrtime(true) - $started) / 1000),
            'keep_alive' => $this->keepAlive,
        ];
        if ($reason !== null) {
            $answer['reason'] = $reason;
        }

        $this->sendJson(MessageType::ACK, $this->requestId, $answer);

        if (!$this->keepAlive) {
            $this->stopping = true;
        }
    }

    /** Reject a job when this worker has no consumer handler. */
    private function refuseJob(Header $header, string $payload): void
    {
        $metadata = $this->decodeJson($payload, 'a JOB header');
        $this->readRequestBody($header->requestId, $this->optionalIntField($metadata, 'body_len'));

        $this->sendJson(MessageType::ACK, $header->requestId, [
            'outcome' => 'reject',
            'reason' => 'this worker serves requests, not jobs',
            'peak_memory_bytes' => 0,
            'duration_us' => 0,
            'keep_alive' => true,
        ]);
    }

    /** Serve one HTTP or gRPC request, selected by its content type. */
    private function serve(Header $header, string $payload): void
    {
        $this->requestId = $header->requestId;
        $this->keepAlive = true;

        $metadata = $this->decodeJson($payload, 'a REQUEST header');

        // **A multipart body is parsed while it is being read, not after.** That is the whole
        // difference between a worker that can accept a 1 GiB upload and one that cannot: the
        // parser writes each file part to disk as its bytes arrive, so peak memory is the size
        // of the largest text field rather than the size of the body.
        //
        // The body is *also* kept as a string, which is why this is not purely incremental: an
        // application that reads `$request->body` — or parses multipart itself — has always seen
        // the raw bytes, and PHP's own `php://input` being empty for multipart is a behaviour
        // worth being a superset of rather than copying. See `Multipart`'s class comment.
        $contentType = $this->headerValue($metadata, 'content-type') ?? '';
        $multipart = null;
        if (Multipart::isMultipart($contentType)) {
            $multipart = new Multipart($contentType);
            $body = $this->readRequestBody($header->requestId, $this->optionalIntField($metadata, 'body_len'), $multipart);
            $multipart->finish();
        } else {
            $body = $this->readRequestBody($header->requestId, $this->optionalIntField($metadata, 'body_len'));
        }

        $started = hrtime(true);
        // Capture HTTP output; gRPC output is discarded rather than treated as a message.
        $this->beginCapture();

        // Install globals for HTTP requests and clear them in finally to prevent state leaking.
        $this->currentPost = $multipart?->post() ?? [];
        $this->currentFiles = $multipart?->files() ?? [];

        // Share one request instance between globals and the handler.
        $request = $this->buildRequest($metadata, $body);

        $this->installingGlobals = $this->cgiGlobals && !$request->isGrpc();
        if ($this->installingGlobals) {
            Globals::install($request, $this->currentPost, $this->currentFiles);
        }

        // The shutdown responder handles requests that end with exit before respond() is reached.
        $this->requestUnanswered = true;

        try {
            if ($request->isGrpc()) {
                $response = $this->answerGrpc($request, $started);
            } else {
                $handler = $this->httpHandler;
                if ($handler === null) {
                    throw new ProtocolException(
                        'an HTTP request arrived at a worker that registered no HTTP handler; call '
                        . 'Worker::run() or Worker::onGrpc()'
                    );
                }
                $result = $handler($request, $this);
                $captured = $this->endCapture();
                // Streamed responses send their head before the body is available.
                if ($result instanceof StreamedResponse) {
                    $this->respondStreamed($result, 0, (int) ((hrtime(true) - $started) / 1000));
                    return;
                }
                $response = $this->normalise($result, $captured);
            }
            $appStatus = 0;
        } catch (\Throwable $exception) {
            // Recover any captured output before reporting the failure.
            $this->endCapture();

            $this->diagnose(sprintf(
                'uncaught %s: %s in %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            // gRPC clients read errors from trailers; the request may not have been built yet.
            $response = $this->looksLikeGrpc($metadata)
                ? $this->grpcResponse(
                    GrpcResponse::fail(GrpcResponse::INTERNAL, 'the application failed while serving this call'),
                    (int) ((hrtime(true) - $started) / 1000),
                )
                : Response::text("Internal Server Error\n", 500);
            $appStatus = 1;
        } finally {
            // Clear request globals before removing uploaded files.
            if ($this->installingGlobals) {
                Globals::reset();
                $this->installingGlobals = false;
            }
            $this->currentPost = [];
            $this->currentFiles = [];
            $multipart?->cleanup();
        }

        $durationUs = (int) ((hrtime(true) - $started) / 1000);

        $this->respond($response, $appStatus, $durationUs);
    }

    /**
     * Answer one gRPC call with the registered handler, or say `UNIMPLEMENTED` if there is none.
     *
     * Held separately from the loop in [`Worker::serve`] rather than inlined, because that method has a
     * `catch` that both protocols share and this is the half that has to be readable on its own: every
     * branch here turns into a status a client reads.
     */
    private function answerGrpc(Request $request, int $started): Response
    {
        $handler = $this->grpcHandler;
        if ($handler === null) {
            // An unregistered gRPC method is a normal UNIMPLEMENTED response.
            $this->endCapture();

            return $this->grpcResponse(
                GrpcResponse::fail(
                    GrpcResponse::UNIMPLEMENTED,
                    'this worker registered no gRPC handler; call Worker::onGrpc()',
                ),
                (int) ((hrtime(true) - $started) / 1000),
            );
        }

        try {
            $answer = $handler($request, $this);
        } catch (\Throwable $exception) {
            // Log the exception and return the gRPC INTERNAL status.
            $this->diagnose(sprintf(
                'uncaught %s while serving %s: %s in %s:%d',
                $exception::class,
                $request->uri,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            $answer = GrpcResponse::fail(
                GrpcResponse::INTERNAL,
                'the application failed while serving this call',
            );
        }

        $this->endCapture();

        return $this->grpcResponse($answer, (int) ((hrtime(true) - $started) / 1000));
    }

    /**
     * Whether a REQUEST's metadata describes a gRPC call, without building a [`Request`] first.
     *
     * Needed because the failure path in [`Worker::serve`] has to know which *kind* of reply to produce
     * while the thing that normally answers that — a built `Request` — may be exactly what failed to be
     * built. The rule is [`Request::isGrpc`]'s, reached from the raw metadata; the two are the same
     * question and the header is the only thing either asks about.
     *
     * @param array<string, mixed> $metadata
     */
    private function looksLikeGrpc(array $metadata): bool
    {
        if (!isset($metadata['headers']) || !is_array($metadata['headers'])) {
            return false;
        }

        foreach ($metadata['headers'] as $pair) {
            if (is_array($pair) && count($pair) === 2 && strcasecmp((string) $pair[0], 'content-type') === 0) {
                $type = strtolower(trim((string) $pair[1]));
                if (!str_starts_with($type, 'application/grpc')) {
                    return false;
                }
                $rest = substr($type, strlen('application/grpc'));

                return $rest === '' || $rest[0] === ';' || $rest[0] === '+' || $rest[0] === '-';
            }
        }

        return false;
    }

    /**
     * Write the response head, body frames, and terminal status.
     *
     * The body length is declared before sending and must match the frames.
     */
    private function respond(Response $response, int $appStatus, int $durationUs): void
    {
        // The shutdown responder must not send a second response after this point.
        $this->requestUnanswered = false;

        $body = $response->body;

        $this->sendJson(MessageType::RESPONSE, $this->requestId, [
            'status' => $response->status,
            'headers' => $response->headers,
            'body_len' => strlen($body),
        ]);

        if ($body !== '') {
            $chunks = str_split($body, self::MAX_BODY_CHUNK);
            $last = count($chunks) - 1;
            foreach ($chunks as $index => $chunk) {
                $this->send(new Frame(
                    MessageType::BODY,
                    $this->requestId,
                    $chunk,
                    $index === $last ? Header::FLAG_LAST : 0,
                ));
            }
        }

        $this->sendJson(MessageType::END, $this->requestId, [
            'app_status' => $appStatus,
            'peak_memory_bytes' => $this->peakMemoryBytes(),
            'duration_us' => $durationUs ?? 0,
            'keep_alive' => $this->keepAlive,
        ]);

        if (!$this->keepAlive) {
            $this->stopping = true;
        }
    }

    /**
     * Stream a response, marking only the final non-empty chunk with `FLAG_LAST`.
     *
     * If iteration fails after the response head is sent, leave the body unterminated so
     * the server reports truncation; a second status cannot be sent.
     */
    private function respondStreamed(StreamedResponse $response, int $appStatus, int $durationUs): void
    {
        // Same rule as `respond()`: the reply has started, so a later `exit` owes nothing.
        $this->requestUnanswered = false;

        // Create the generator before sending the head so factory errors can become a 500.
        $chunks = $response->stream();

        $this->sendJson(MessageType::RESPONSE, $this->requestId, [
            'status' => $response->status,
            'headers' => $response->headers,
            // **Declared as zero and flagged**, which is the protocol's way of saying "unknown"
            // rather than "empty". The two spellings must never both be used for one head.
            'body_len' => 0,
        ], Header::FLAG_STREAMED);

        $held = null;
        $failed = false;
        try {
            foreach ($chunks as $chunk) {
                $chunk = (string) $chunk;
                if ($chunk === '') {
                    continue;
                }
                if ($held !== null) {
                    $this->sendBody($held, false);
                }
                $held = $chunk;
            }
        } catch (\Throwable $exception) {
            $failed = true;
            $this->diagnose(sprintf(
                'streamed response aborted after %s: %s in %s:%d',
                $held === null ? 'no chunks' : 'a chunk was sent',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));
        }

        if ($failed) {
            // Preserve yielded data, but omit LAST and END so the server detects truncation.
            if ($held !== null) {
                $this->sendBody($held, false);
            }
            $this->stopping = true;
            return;
        }

        if ($held !== null) {
            $this->sendBody($held, true);
        } else {
            // An empty stream needs an empty final frame to signal completion.
            $this->send(new Frame(MessageType::BODY, $this->requestId, '', Header::FLAG_LAST));
        }

        $this->sendJson(MessageType::END, $this->requestId, [
            'app_status' => $appStatus,
            'peak_memory_bytes' => $this->peakMemoryBytes(),
            'duration_us' => $durationUs,
            'keep_alive' => $this->keepAlive,
        ]);

        if (!$this->keepAlive) {
            $this->stopping = true;
        }
    }

    /**
     * Write one body chunk, split at the protocol's frame limit.
     *
     * Only the **last** piece of the **last** chunk carries `LAST`, which is why this takes the flag
     * as an argument rather than inferring it: the caller is the only place that knows whether more
     * is coming.
     */
    private function sendBody(string $chunk, bool $last): void
    {
        $pieces = str_split($chunk, self::MAX_BODY_CHUNK);
        $count = count($pieces);
        foreach ($pieces as $index => $piece) {
            $this->send(new Frame(
                MessageType::BODY,
                $this->requestId,
                $piece,
                $last && $index === $count - 1 ? Header::FLAG_LAST : 0,
            ));
        }
    }

    /**
     * Return peak memory usage since the previous request, in bytes.
     *
     * Reset the high-water mark after reading it so each END frame reports
     * request-level usage.
     *
     * @see https://www.php.net/manual/en/function.memory-reset-peak-usage.php
     */
    private function peakMemoryBytes(): int
    {
        $peak = memory_get_peak_usage(true);

        if (\function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        return $peak;
    }

    /**
     * Emit a log record without touching stdout.
     *
     * The protocol exists so a worker can say something *outside* a request;
     * writing the line to stdout instead would corrupt the stream, so this is
     * the supported way.
     */
    public function emitLog(string $message, string $level = 'info'): void
    {
        $this->sendJson(MessageType::LOG, self::UNSOLICITED, [
            'level' => $level,
            'message' => $message,
            'request_id' => $this->requestId,
        ]);
    }

    /**
     * Emit a metric sample.
     *
     * Names must match Prometheus syntax and be listed in `[metrics.collect]`.
     */
    public function emitMetric(string $name, float $value, string $unit = ''): void
    {
        $this->sendJson(MessageType::METRIC, self::UNSOLICITED, [
            'name' => $name,
            'value' => $value,
            'unit' => $unit,
        ]);
    }

    /**
     * Register a handler for server-initiated calls during a request.
     *
     * Return a value, or an `ok => false` array to refuse the call. Set
     * `unknown => true` when it may have taken effect; thrown exceptions become refusals.
     *
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function onCall(callable $handler): void
    {
        $this->callHandler = $handler;
    }

    /**
     * Answer a `CALL` from the server.
     *
     * Writes a `RETURN` on the call's own id in every case, including when there is no handler and
     * when the handler threw. That is the invariant: the server is waiting, and a missing answer is
     * indistinguishable from a dead worker until a deadline turns it into one.
     */
    private function answerCall(Header $header, string $payload): void
    {
        $call = $this->decodeJson($payload, 'a CALL');

        try {
            if ($this->callHandler === null) {
                $this->sendJson(MessageType::RETURN, $header->requestId, [
                    'ok' => false,
                    'error' => 'this worker registered no call handler, so '
                        . (string) ($call['name'] ?? 'the call') . ' cannot be served',
                ]);

                return;
            }

            $result = ($this->callHandler)($call);

            // A handler that wants to say anything other than "here is the value" returns an array
            // with `ok` in it. Checking for the key rather than for a specific shape means a result
            // that happens to be an array of data is still sent as a result.
            if (is_array($result) && array_key_exists('ok', $result)) {
                $this->sendJson(MessageType::RETURN, $header->requestId, [
                    'ok' => (bool) $result['ok'],
                    'error' => $result['error'] ?? null,
                    'unknown' => (bool) ($result['unknown'] ?? false),
                    'result' => $result['result'] ?? null,
                ]);

                return;
            }

            $this->sendJson(MessageType::RETURN, $header->requestId, [
                'ok' => true,
                'result' => $result,
            ]);
        } catch (\Throwable $exception) {
            // Caught rather than propagated, and this is the one place in the SDK where a caught
            // exception does *not* fail the request: the loop is mid-request, the application's
            // handler is what threw, and the server is still owed an answer. Propagating would exit
            // the process and lose the request too, which is strictly worse than refusing one call.
            $this->diagnose(sprintf(
                'a call handler threw %s: %s in %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            $this->sendJson(MessageType::RETURN, $header->requestId, [
                'ok' => false,
                'error' => 'the call handler threw ' . $exception::class,
                // Deliberately *not* unknown: the handler threw before returning, and whether its
                // side effects happened is the handler's business to report. Guessing "unknown" here
                // would make every throw look like a possible effect and teach a caller to distrust
                // the flag.
                'unknown' => false,
            ]);
        }
    }

    /**
     * Ask the server to replace this worker after the current request.
     *
     * For an application that has put itself into a state it cannot clean up —
     * a leaked global, a stuck connection, a fat static cache. The server sees
     * `keep_alive = false` in the END frame and boots a replacement instead of
     * reusing this process.
     *
     * The current request is still answered normally, so a handler never has to
     * choose between responding and being cleaned up: this only affects what
     * happens *after* it.
     */
    public function recycle(): void
    {
        $this->keepAlive = false;
    }

    /**
     * The request currently being served, for telemetry correlation.
     */
    public function currentRequestId(): int
    {
        return $this->requestId;
    }

    /**
     * A handle to one of the server's key-value storages.
     *
     * The storage must be one this worker's backend was granted in its `services` list; a call naming
     * another is refused by the server with the storages that *are* reachable, which is a better error
     * than anything this side could produce.
     *
     * Returns a new handle each time rather than memoizing one, because the serializer is per-instance:
     * two parts of an application can legitimately want different encodings of the same storage, and a
     * shared handle would make the second `serializer()` call silently change the first one's behaviour.
     *
     * @param string $storage the name from the `[kv.<name>]` table.
     */
    public function kv(string $storage = 'default'): Kv
    {
        return new Kv($this, $storage);
    }

    /**
     * A handle to the server's resource locks.
     *
     * One lock plane and not one per name: a lock is named by its **resource**, so `[lock]` is a single
     * table and a worker reaches it by putting `"lock"` in its backend's `services` list. There is
     * nothing to choose here, which is why this takes no argument where {@see Worker::kv()} takes a
     * storage name.
     *
     * Returns a fresh handle each call, like `kv()`, and for a smaller reason: the object holds no
     * state at all, so sharing one would be harmless — but a caller that mutated a shared one later
     * would be a surprise, and constructing it costs nothing.
     */
    public function lock(): Lock
    {
        return new Lock($this);
    }

    /**
     * A handle to one of the server's queues, from the producer's side.
     *
     * The pipeline must be one the deployment configured; a push naming another is refused by the server
     * with the pipelines that *are* configured, which is a better error than anything this side could
     * produce. Note that a queue is **not** scoped per backend the way a KV storage is — the `jobs`
     * service grants pushing to every pipeline — so the name is the only thing that selects one.
     *
     * Producing only: see {@see Jobs}'s class documentation for why `reserve` is not here.
     *
     * @param string $pipeline the name from the `[jobs.pipelines.<name>]` table.
     */
    public function queue(string $pipeline): Jobs
    {
        return new Jobs($this, $pipeline);
    }

    // -------------------------------------------------------------------- calls

    /**
     * Call a server service from within a request handler.
     *
     * @param string $name   `"<namespace>.<verb>"`, e.g. `kv.get`.
     * @param array<string, mixed> $params method-specific arguments.
     * @return mixed the result, or null on refusal; see {@see Worker::lastCallError()}.
     * @throws ProtocolException if the stream desynchronised or no request is active.
     */
    public function call(string $name, array $params = []): mixed
    {
        if ($this->requestId === self::UNSOLICITED) {
            throw new ProtocolException(sprintf(
                'call("%s") was made outside a request: the server has nobody reading this worker '
                . 'between requests, so the answer would never be seen',
                $name,
            ));
        }

        $id = $this->nextCallId();
        $this->lastCallError = null;
        $this->lastCallUnknown = false;
        $this->sendJson(MessageType::CALL, $id, ['name' => $name, 'params' => $params]);

        return $this->awaitReturn($id, $name);
    }

    /**
     * Why the last {@see Worker::call()} did not return a result, or null if it did.
     *
     * Separate from the return value because a call and a function that returns null are not the same
     * thing: `kv.get` on a missing key returns null and *succeeded*, and a caller that could not tell
     * the two apart would treat a cache miss as a failure. Set on every call, so reading it after a
     * `null` result is meaningful and reading it after a non-null one is not.
     */
    public function lastCallError(): ?string
    {
        return $this->lastCallError;
    }

    /**
     * Whether the call that failed may have taken effect.
     *
     * The distinction the `RETURN` frame carries and the server documents: a definite refusal is safe
     * to retry, and an unknown state is not, because retrying it duplicates a side effect. Exposed
     * rather than folded into the error string so that a caller can branch on it.
     */
    public function lastCallWasUnknown(): bool
    {
        return $this->lastCallUnknown;
    }

    /**
     * The next call id, counting down from the top of the range.
     *
     * Counted down so that the two id spaces cannot collide: request ids are the server's and start at
     * 1, call ids are this worker's and start at `0xFFFFFFFF`.
     */
    private function nextCallId(): int
    {
        $id = $this->nextCallId;
        // Wrapping to `0x80000001` rather than to zero: below `0x80000000` an id would look like a
        // request's to the server, which decides by which half of the range an id falls in. The wrap
        // is four billion calls away and the alternative is a correlation failure that looks real.
        $this->nextCallId = $id <= 0x80000001 ? 0xFFFFFFFF : $id - 1;

        return $id;
    }

    /**
     * Wait for a call result while dispatching other control frames.
     *
     * The server guarantees a RETURN; call deadlines are enforced server-side.
     *
     * @return mixed
     */
    private function awaitReturn(int $id, string $name): mixed
    {
        while (true) {
            $header = $this->readHeader();
            if ($header === null) {
                $this->lastCallError = sprintf('call "%s": the server closed the connection', $name);
                $this->lastCallUnknown = true;
                throw new ProtocolException($this->lastCallError);
            }
            $payload = $this->readPayload($header);

            switch ($header->messageType) {
                case MessageType::RETURN:
                    if ($header->requestId !== $id) {
                        // A different call's answer, which cannot happen while calls are issued
                        // sequentially — and if it does, the two id spaces have been mixed up.
                        throw new ProtocolException(sprintf(
                            'expected the answer to call 0x%08X but the server answered 0x%08X',
                            $id,
                            $header->requestId,
                        ));
                    }

                    return $this->acceptReturn($payload, $name);
                case MessageType::CALL:
                    $this->answerCall($header, $payload);
                    break;
                case MessageType::PING:
                    $this->send(new Frame(MessageType::PONG, self::UNSOLICITED));
                    break;
                case MessageType::CONTROL:
                    $this->handleControl($payload);
                    break;
                default:
                    throw new ProtocolException(sprintf(
                        'the server sent a %s while call "%s" was outstanding, which is not a frame '
                        . 'a worker can receive',
                        $header->typeName(),
                        $name,
                    ));
            }
        }
    }

    /**
     * Turn a `RETURN` payload into a result, or into a recorded error.
     *
     * @return mixed
     */
    private function acceptReturn(string $payload, string $name): mixed
    {
        $answer = $this->decodeJson($payload, 'a RETURN');

        if (($answer['ok'] ?? true) === true) {
            return $answer['result'] ?? null;
        }

        $this->lastCallError = (string) ($answer['error'] ?? 'the server refused the call without saying why');
        $this->lastCallUnknown = ($answer['unknown'] ?? false) === true;

        return null;
    }

    // ---------------------------------------------------------------- handshake

    /**
     * Exchange HELLO for HELLO_ACK.
     *
     * Tolerant about what arrives before and after the HELLO, but strict about
     * the version: a mismatched SDK is a configuration problem, and it should be
     * reported at boot rather than on the first request.
     */
    private function handshake(): void
    {
        while (true) {
            $header = $this->readHeader();
            if ($header === null) {
                throw new ProtocolException('the server closed the pipe before the handshake');
            }

            $payload = $this->readPayload($header);

            if ($header->messageType === MessageType::HELLO) {
                // The offer, taken from the HELLO. It is deliberately *not* acted on yet: a
                // worker that stopped checksumming before its answer had been read and
                // understood would be trusting a frame it has not yet answered.
                $offer = false;
                if ($header->payloadLen > 0) {
                    $hello = $this->decodeJson($payload, 'a HELLO');
                    $version = $this->intField($hello, 'protocol_version', Header::PROTOCOL_VERSION);
                    if ($version !== Header::PROTOCOL_VERSION) {
                        throw new ProtocolException(sprintf(
                            'the server speaks protocol version %d, this SDK speaks %d',
                            $version,
                            Header::PROTOCOL_VERSION,
                        ));
                    }
                    $offer = $this->boolField($hello, 'no_checksum', false);
                }
                break;
            }

            if ($header->messageType === MessageType::LOG
                || $header->messageType === MessageType::METRIC
                || $header->messageType === MessageType::EVENT
            ) {
                continue;
            }

            throw new ProtocolException(
                'expected a HELLO to begin the handshake, got a ' . $header->typeName()
            );
        }

        // The answer is sent *before* the switch, so the `HELLO_ACK` always carries a real
        // checksum: both ends are still verifying at the moment the agreement is exchanged.
        // Only then does the worker adopt it, and only if it agreed.
        $this->sendJson(MessageType::HELLO_ACK, self::UNSOLICITED, [
            'protocol_version' => Header::PROTOCOL_VERSION,
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'pid' => (int) getmypid(),
            'runtime' => self::runtime(),
            'no_checksum' => $offer,
            // **This SDK can read a body whose length it was not told.** The server only sends
            // `REQUEST` without `body_len` to a worker that says so here, because the alternative
            // is worse than a missing feature: an SDK that predates the field reads an absent
            // `body_len` as `0`, concludes there is nothing to read, and then takes the first
            // `BODY` frame for its own response. One absent key would desynchronise every request,
            // which is why the party that has to cope is the one that advertises.
            'stream_request_body' => true,
            // **This worker answers `PING` with `PONG`**, which `run()` has done since the first
            // version — but the server could not know that, so it never sent one. Advertising it is
            // what lets a deployment set `idle_ping_ms` and have a quiet pool kept alive.
            //
            // The direction of this rule is not politeness: a worker that does not understand
            // `PING` does not ignore it, it exits (see `run()`'s `default:` arm — skipping an
            // unknown frame would misalign the reader). So a server that probed for the capability
            // by sending one would kill every worker it asked. The party that has to cope is the
            // party that advertises, exactly as with `stream_request_body`.
            'answers_ping' => true,
        ]);

        if ($offer) {
            $this->checksum = false;
        }
    }

    /**
     * Identify this worker to the server: the SDK and the build it is running.
     *
     * The `runtime` field says what *implements* the protocol, as distinct from
     * `php_version` (the runtime the worker is running on) — and a server
     * talking to several backends has to be able to tell them apart in its logs.
     *
     * The build travels here so an operator can discover what is deployed
     * without shelling into the host: the handshake is the one thing a worker
     * always sends, so this figure needs no new field, no new message type, and
     * no protocol change. A server that does not care about it already ignores
     * it, because `runtime` is a free-form string.
     */
    public static function runtime(): string
    {
        return 'volucer-worker-php/' . self::build();
    }

    /**
     * Act on a CONTROL frame, acknowledging it either way.
     *
     * The `CONTROL_ACK` is **best effort**, and the reason is `attach`. Recycling an attached slot
     * is `Slot::stop()` in the server: it sends `CONTROL drain` and then closes the connection
     * *without* reading the answer, because the decision to stop is already made and no reply can
     * change it. A worker that insisted on writing that ack would take a broken pipe on every
     * single recycle — measured as a notice plus an error line per request under `max_requests` —
     * and would then exit as a failure rather than as a clean stop. The ack is a courtesy to a
     * server that is still listening; a server that has gone is not an error this worker made.
     */
    private function handleControl(string $payload): void
    {
        $control = $this->decodeJson($payload, 'a CONTROL');
        $kind = is_string($control['kind'] ?? null) ? $control['kind'] : '';

        $this->acknowledgeControl();

        switch ($kind) {
            case 'drain':
                // Stop accepting work and exit once idle. We are idle between
                // requests, so this is immediate — and the server has already
                // been told, so it will not send another request.
                $this->stopping = true;
                break;

            case 'shutdown':
                // No END is written: END is request-scoped, and a control frame
                // only ever arrives between requests. The CONTROL_ACK is the
                // whole answer — the server already knows it asked us to stop.
                $this->stopping = true;
                break;

            case 'report':
                $this->emitMetric('worker_peak_memory_bytes', (float) $this->peakMemoryBytes(), 'bytes');
                break;

            case 'reload':
                // A worker has no separate bootstrap to re-run: the handler is
                // the application, and it is already loaded. Acknowledged so the
                // server's state machine completes; the server recycles the
                // worker if it needs the code reloaded.
                $this->diagnose('reload acknowledged: this worker has no separate bootstrap');
                break;

            default:
                $this->diagnose("unknown control kind '{$kind}' acknowledged and ignored");
        }
    }

    /**
     * Acknowledge a CONTROL, tolerating a server that closed without reading the answer.
     *
     * `Slot::stop()` in the server closes the connection immediately after sending `CONTROL drain`,
     * so this write legitimately fails on every recycle of an `attach` slot. The failure carries no
     * information — the server made a decision no ack can change — so it is swallowed here rather
     * than promoted to a protocol error. A `send()` failure with any *other* cause still surfaces,
     * because those are bugs rather than this one known shape.
     *
     * The write goes through {@see write()} with the error suppressed at the call rather than with a
     * process-wide `error_reporting()` change: a warning printed by PHP's handler would otherwise
     * reach stderr before the exception could be caught, and toggling the global level for the
     * duration of a write is a side effect on an application that never asked for one.
     */
    private function acknowledgeControl(): void
    {
        $bytes = (new Frame(MessageType::CONTROL_ACK, self::UNSOLICITED))->encode($this->checksum);
        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($this->out, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false || $written === 0) {
                // The server is gone. Not this worker's failure, and not a reason to exit non-zero.
                return;
            }
            $offset += $written;
        }

        @fflush($this->out);
    }

    // ------------------------------------------------------------ request side

    /**
     * Read the request body from the BODY frames that follow a REQUEST.
     *
     * Stops at `body_len` bytes, because the declared length is authoritative.
     * A frame flagged LAST that arrives early means the sender and this reader
     * disagree about the length, which is worth failing on rather than papering
     * over: the rest of the stream cannot be trusted after that.
     *
     * **`$sink` is fed each frame as it arrives**, before the frame is appended. A multipart
     * parser passed here therefore sees the body incrementally and can spool a file part to
     * disk immediately, which is what keeps peak memory off the upload's size. The string is
     * still built, because `$request->body` has always carried the raw bytes and an application
     * that parses multipart itself would otherwise lose them.
     */
    private function readRequestBody(int $requestId, ?int $bodyLen, ?Multipart $sink = null): string
    {
        // `null` means the server did not declare a length: the body is streamed and `LAST` is the
        // only end signal. An explicit `0` is a genuinely empty body and short-circuits here.
        if ($bodyLen === 0) {
            return '';
        }

        $body = '';
        while ($bodyLen === null || strlen($body) < $bodyLen) {
            $header = $this->readHeader();
            if ($header === null) {
                throw new ProtocolException($bodyLen === null
                    ? 'the server closed the pipe in the middle of a streamed request body'
                    : sprintf(
                        'the server closed the pipe after %d of %d body bytes',
                        strlen($body),
                        $bodyLen,
                    ));
            }

            $payload = $this->readPayload($header);

            if ($header->messageType !== MessageType::BODY) {
                throw new ProtocolException(
                    'expected a BODY while reading the request body, got a ' . $header->typeName()
                );
            }

            if ($header->requestId !== $requestId) {
                throw new ProtocolException(sprintf(
                    'a BODY for request %d arrived while reading request %d',
                    $header->requestId,
                    $requestId,
                ));
            }

            $sink?->feed($payload);
            $body .= $payload;

            if ($bodyLen === null) {
                // **`LAST` is the terminator, and this is the only place a streamed body ends.**
                // It is checked *after* the payload has been fed to the sink, so a final frame that
                // also carried the last bytes of a file is not dropped.
                if ($header->isLast()) {
                    break;
                }
                continue;
            }

            if ($header->isLast() && strlen($body) !== $bodyLen) {
                throw new ProtocolException(sprintf(
                    'the last BODY frame arrived after %d of %d declared bytes',
                    strlen($body),
                    $bodyLen,
                ));
            }
        }

        return $body;
    }

    /**
     * Build the [`Request`] from the REQUEST metadata.
     *
     * @param array<string, mixed> $metadata
     */
    private function buildRequest(array $metadata, string $body): Request
    {
        /** @var list<array{0: string, 1: string}> $rawHeaders */
        $rawHeaders = [];
        if (isset($metadata['headers']) && is_array($metadata['headers'])) {
            foreach ($metadata['headers'] as $pair) {
                if (is_array($pair) && count($pair) === 2) {
                    $rawHeaders[] = [(string) $pair[0], (string) $pair[1]];
                }
            }
        }

        return new Request(
            method: $this->stringField($metadata, 'method', 'GET'),
            uri: $this->stringField($metadata, 'uri', '/'),
            protocol: $this->stringField($metadata, 'protocol', 'HTTP/1.1'),
            headers: $this->headersToMap($rawHeaders),
            remoteAddr: $this->stringField($metadata, 'remote_addr'),
            scriptFilename: $this->stringField($metadata, 'script_filename'),
            https: (bool) ($metadata['https'] ?? false),
            body: $body,
            post: $this->currentPost,
            files: $this->currentFiles,
        );
    }

    /**
     * Collapse `[['Accept', 'text/html'], …]` into a name → value map.
     *
     * Repeated names are joined with a comma, which is what HTTP says a repeated
     * field means. (`Set-Cookie` is the famous exception, and it only matters in
     * the response direction, where [`Response`] keeps the list form.)
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<string, string>
     */
    private function headersToMap(array $pairs): array
    {
        $map = [];
        foreach ($pairs as [$name, $value]) {
            if (isset($map[$name])) {
                $map[$name] .= ', ' . $value;
            } else {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /**
     * A `[[name, value], …]` field, filtered to the shape it claims to be.
     *
     * The job header's `headers` arrive as a list of pairs, where a request's arrive as the same
     * shape — so the parsing matches the request path's, including the one liberty it takes: a
     * non-string name or value is *cast* rather than dropped, because a producer that wrote `3` as a
     * header name meant `"3"` and failing every job in the pipeline over it would be a worse answer. A
     * pair that is not two elements is dropped, which is the case a malformed frame actually produces.
     *
     * @param array<string, mixed> $data
     * @return list<array{0: string, 1: string}>
     */
    private function pairsField(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $pairs = [];
        /** @var mixed $pair */
        foreach ($value as $pair) {
            if (!is_array($pair) || count($pair) !== 2) {
                continue;
            }
            $pair = array_values($pair);
            if (!is_scalar($pair[0]) || !is_scalar($pair[1])) {
                continue;
            }
            $pairs[] = [(string) $pair[0], (string) $pair[1]];
        }

        return $pairs;
    }

    /**
     * Read exactly `$length` bytes, waiting out an idle connection rather than failing on it.
     *
     * The payload counterpart of {@see Worker::readHeader}, and the same distinction: a short
     * read means either "the connection ended mid-frame", which is a protocol failure and must
     * be reported, or "the read deadline passed and the rest is still coming", which is an idle
     * connection and must be waited out.
     *
     * Getting this wrong is quieter than it sounds. A payload that timed out half-read is
     * reported as a truncated frame, which is a `ProtocolException`, which propagates out of
     * `run()` and **kills the worker** — the same outcome as the header bug, reached through a
     * different door, and one that only shows up when the server is slow rather than idle.
     */
    private function readExactly(int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $piece = stream_get_contents($this->in, $length - strlen($buffer));

            if ($piece === false || $piece === '') {
                if ($this->readTimedOut()) {
                    continue;
                }

                throw new ProtocolException(sprintf(
                    'a frame declared %d payload bytes but the stream ended after %d',
                    $length,
                    strlen($buffer),
                ));
            }

            $buffer .= $piece;
        }

        return $buffer;
    }

    /**
     * Turn whatever the handler returned into a [`Response`].
     */
    private function normalise(mixed $result, string $captured): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if ($result === null) {
            // The `echo` case: the application's own output is the body.
            return Response::html($captured);
        }

        throw new ProtocolException(
            'a worker handler must return a Response, a string or null, got ' . get_debug_type($result)
        );
    }

    /**
     * Register a gRPC handler.
     *
     * Calls are identified by their content type. The handler may return a
     * {@see GrpcResponse}, response bytes, or null for an empty message.
     *
     * @param callable(Request, self): (GrpcResponse|string|null) $handler
     */
    public function onGrpc(callable $handler): void
    {
        $this->grpcHandler = $handler;
    }

    /**
     * Register the handler for gRPC calls **and** serve them, with no HTTP routes.
     *
     * The convenience for a worker that only speaks gRPC — `onGrpc()` followed by `run()`, where the
     * HTTP handler is one that says by name that it has no routes rather than leaving the caller to
     * invent it. A worker that serves both calls the two halves separately; see [`Worker::onGrpc`].
     *
     * @param callable(Request, self): (GrpcResponse|string|null) $handler
     */
    public function grpc(callable $handler): void
    {
        $this->onGrpc($handler);

        $this->run(static fn (Request $request): Response => Response::text(
            "this worker serves gRPC calls; an HTTP request reached it because its backend has no\n"
            . "HTTP route and this process has no HTTP handler\n",
            404,
        ));
    }

    /**
     * Convert a gRPC result to the protocol response.
     *
     * The server moves `grpc-status` and `grpc-message` headers into response trailers.
     */
    private function grpcResponse(mixed $answer, int $durationUs): Response
    {
        if ($answer === null) {
            $answer = GrpcResponse::ok();
        } elseif (is_string($answer)) {
            $answer = GrpcResponse::message($answer);
        }

        if (!$answer instanceof GrpcResponse) {
            // A `ProtocolException` rather than a 500, because it is not the *call* that failed — the
            // application did not answer it at all. `serve()` turns a thrown exception into a status
            // and a log line, which is the honest outcome for a handler that is broken.
            throw new ProtocolException(
                'a gRPC handler must return a GrpcResponse, a string or null, got '
                . get_debug_type($answer)
            );
        }

        $response = new Response(
            status: 200,
            headers: [
                ['Content-Type', $answer->contentType],
                ['grpc-status', (string) $answer->status],
            ],
            body: $answer->payload,
        );

        // Only when there is one. An empty `grpc-message` and an absent one mean the same thing to a
        // client, and only one of them is true of a call that has nothing to say.
        if ($answer->reason !== '') {
            $response->headers[] = ['grpc-message', $answer->reason];
        }

        return $response;
    }

    // ----------------------------------------------------------- output capture

    /**
     * Start capturing the application's output.
     *
     * The current buffer depth is recorded so [`Worker::endCapture`] can tell
     * an inner buffer the handler forgot to close from our own, and can still
     * recover if the handler unwound ours.
     */
    private function beginCapture(): void
    {
        $this->captureLevel = ob_get_level();
        ob_start();
    }

    /**
     * Send a response from the shutdown handler when application code calls exit.
     *
     * A process-wide handler is registered once because PHP cannot unregister shutdown
     * callbacks. It answers only while a request is pending; the exit code and headers
     * are unavailable, so the fallback response is HTTP 200 with captured output.
     */
    private function registerShutdownResponder(): void
    {
        if ($this->shutdownResponderRegistered) {
            return;
        }

        $this->shutdownResponderRegistered = true;

        register_shutdown_function(function (): void {
            // **Drain before trapping.** `ob_get_clean()` closes the *innermost* buffer, so a
            // trap opened first would be the thing drained and the page would be left to flush
            // into the protocol channel. Innermost first here, then reversed, because the order
            // the buffers would have flushed in is outermost first.
            $chunks = [];
            while (ob_get_level() > $this->captureLevel) {
                $drained = ob_get_clean();
                if (is_string($drained)) {
                    $chunks[] = $drained;
                }
            }

            // **The trap goes on in every case.** stdout is the protocol channel, so a shutdown
            // function the application registered — WordPress's own fatal-error handler is one —
            // would otherwise print straight into the frame stream when the worker stops.
            ob_start();
            register_shutdown_function(static function (): void {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            });

            if (!$this->requestUnanswered) {
                // Nothing is owed: the request returned normally, or the process is stopping
                // between requests. What was drained is not a response, so it is discarded.
                return;
            }

            try {
                $this->respond(Response::html(implode('', array_reverse($chunks))), 0, 0);
            } catch (\Throwable) {
                // The pipe is already gone, or a frame was half-written. There is no client left
                // to tell and no process left to serve, so the diagnostic is all that is owed.
                @fwrite($this->err, "[volucer] could not answer a request ended by exit()\n");
            }
        });
    }

    /**
     * Stop capturing and return what the application printed.
     *
     * Written defensively because a handler can leave buffers unbalanced. The
     * goal is that one badly-behaved request cannot corrupt the frame stream or
     * leak its output into the next request's response — not to punish the
     * handler, which will see its own exception if it does.
     */
    private function endCapture(): string
    {
        // Close any buffers the handler opened and did not close.
        while (ob_get_level() > $this->captureLevel + 1) {
            ob_end_clean();
        }

        if (ob_get_level() > $this->captureLevel) {
            $captured = ob_get_clean();

            return is_string($captured) ? $captured : '';
        }

        // Our buffer is already gone: the handler ended it, so the output is
        // lost and cannot be recovered. Not worth failing the request over.
        return '';
    }

    // ------------------------------------------------------------------- codec

    /**
     * Read one complete frame header, or return null on clean EOF.
     *
     * Reassemble partial reads and treat timeouts as idle periods, not connection closure.
     * EOF inside a header is a protocol error.
     */
    private function readHeader(): ?Header
    {
        $buffer = '';

        while (strlen($buffer) < Header::HEADER_LEN) {
            $piece = stream_get_contents($this->in, Header::HEADER_LEN - strlen($buffer));

            if ($piece === false || $piece === '') {
                if ($this->readTimedOut()) {
                    // Nothing arrived and nobody left: the server is idle, not finished.
                    continue;
                }

                if ($buffer === '') {
                    // A clean end *between* frames: the server is finished with this worker.
                    return null;
                }

                // A clean end *inside* a frame: the stream really did stop mid-header, and that
                // is a protocol failure rather than a shutdown. Reported as such instead of being
                // handed to `Header::decode`, which would describe it as a malformed frame.
                throw new ProtocolException(sprintf(
                    'a frame header is %d bytes but the stream ended after %d',
                    Header::HEADER_LEN,
                    strlen($buffer),
                ));
            }

            $buffer .= $piece;
        }

        return Header::decode($buffer);
    }

    /**
     * Whether the last read hit its deadline with the connection still open.
     *
     * Two readings of one fact, because either alone is weaker than it looks: `feof` answers "is
     * this stream at its end", which is false for a socket whose peer merely went quiet, and
     * `timed_out` answers "did the last call run out of time". A read that returns `''` is one of
     * those two, and getting it wrong in the "closed" direction kills a healthy worker.
     */
    private function readTimedOut(): bool
    {
        if (feof($this->in)) {
            return false;
        }

        $meta = @stream_get_meta_data($this->in);

        return is_array($meta) && ($meta['timed_out'] ?? false);
    }

    /**
     * Read a frame's payload and verify its checksum.
     *
     * A mismatch means framing has already desynchronised, so this is fatal by
     * construction: the exception propagates out of `run()` and the process
     * exits, rather than trying to resynchronise a stream that cannot be
     * resynchronised.
     *
     * A frame that declares `FLAG_NO_CHECKSUM` is read without hashing it, but only when this
     * worker has actually agreed to that; otherwise the claim is a protocol violation, because
     * accepting it would let the server turn the check off on a worker that never offered to
     * give it up. The agreement is process-wide and set once, in the handshake.
     */
    private function readPayload(Header $header): string
    {
        if ($header->payloadLen === 0) {
            return '';
        }

        $payload = $this->readExactly($header->payloadLen);

        if (!$header->declaresNoChecksum()) {
            $computed = Frame::crc32c($payload);
            if ($computed !== $header->checksum) {
                throw new ProtocolException(sprintf(
                    'payload checksum mismatch on request %d: expected 0x%08x, computed 0x%08x',
                    $header->requestId,
                    $header->checksum,
                    $computed,
                ));
            }

            return $payload;
        }

        if ($this->checksum) {
            throw new ProtocolException(sprintf(
                'the server sent a %s frame declaring no checksum, which this worker never agreed to',
                $header->typeName(),
            ));
        }

        return $payload;
    }

    /**
     * Write one frame, in full.
     *
     * `fwrite` may write fewer bytes than it was given, so the loop is not
     * paranoia: a short write on a pipe would leave half a frame on the wire and
     * desynchronise the server permanently.
     */
    private function send(Frame $frame): void
    {
        $bytes = $frame->encode($this->checksum);
        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($this->out, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new ProtocolException('the server closed its input while a frame was being written');
            }
            $offset += $written;
        }

        fflush($this->out);
    }

    /**
     * Serialize a payload as JSON and send it.
     */
    private function sendJson(int $messageType, int $requestId, mixed $value, int $flags = 0): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->send(new Frame($messageType, $requestId, $encoded, $flags));
    }

    /**
     * Decode a JSON payload, turning a bad payload into a protocol error.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $payload, string $what): array
    {
        if ($payload === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ProtocolException(
                "{$what} was not valid JSON: {$exception->getMessage()}"
            );
        }

        if (!is_array($decoded)) {
            throw new ProtocolException("{$what} must be a JSON object");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intField(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A field that is genuinely absent when it is not there.
     *
     * `null` and `0` mean different things on the wire and must keep doing so: a `body_len` of `0`
     * is a correctly empty body, while an absent one means "the length is unknown, the body ends at
     * `LAST`". Folding the two together with a `0` default is how a streamed request would be read
     * as an empty one.
     *
     * @param array<string, mixed> $data
     */
    private function optionalIntField(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * One request header out of the REQUEST metadata, looked up case-insensitively.
     *
     * The metadata carries `headers` as a list of `[name, value]` pairs rather than a map,
     * because HTTP header names are case-insensitive and their original spelling is worth
     * keeping; a map would have to pick one key and silently drop a duplicate spelled
     * differently. Building a `Request` merely to read one header would be the wrong shape for
     * a decision `serve()` has to take *before* the request exists.
     *
     * @param array<string, mixed> $data
     */
    private function headerValue(array $data, string $name): ?string
    {
        $pairs = $data['headers'] ?? null;
        if (!is_array($pairs)) {
            return null;
        }

        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) !== 2) {
                continue;
            }
            if (strcasecmp((string) $pair[0], $name) === 0) {
                return (string) $pair[1];
            }
        }

        return null;
    }

    /**
     * A field that must be exactly `true`, not merely truthy.
     *
     * `filter_var(..., FILTER_VALIDATE_BOOL)` would also accept `1`, `"1"`, `"yes"` and `"on"`,
     * and that tolerance is the wrong direction for a field that switches off an integrity
     * check: only the JSON literal `true` — what both ends of this protocol write — should be
     * enough. A `null`, an integer, a missing key and the string `"false"` all answer `false`.
     *
     * @param array<string, mixed> $data
     */
    private function boolField(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;

        return $value === true;
    }

    /**
     * Write a diagnostic line to stderr.
     *
     * Never stdout: a line there would land inside a frame.
     */
    private function diagnose(string $message): void
    {
        fwrite($this->err, '[volucer] ' . $message . "\n");
        fflush($this->err);
    }
}
