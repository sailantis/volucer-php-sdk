<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A persistent worker: the loop that serves requests over the protocol.
 *
 * This is the PHP end of the worker protocol specified in the volucer server
 * (`sailantis/volucer`, `docs/worker-protocol.md`). The shape an application sees
 * is deliberately three lines:
 *
 * ```php
 * $worker = Worker::connect();
 * $worker->run(function (Request $request, Worker $worker): Response {
 *     return Response::text('hello ' . $request->uri);
 * });
 * ```
 *
 * ## The two rules
 *
 * The protocol document names two rules that prevent the classic bugs, and both
 * are enforced here rather than merely documented:
 *
 * ### 1. stdout is the protocol channel
 *
 * Every frame this class writes goes to a handle on `php://stdout`. If the
 * application wrote there too — via `echo`, a warning, a notice — its output
 * would land *inside* a frame and desynchronise the stream for good.
 *
 * So the application's own output is not banned, it is **captured**:
 * `ob_start()` around each request collects `echo` and template output, and
 * [`ini_set`]`('display_errors', 'stderr')` in [`Worker::create`] sends
 * warnings and notices to stderr, which is not part of the protocol. Captured
 * output becomes the response body when the handler does not supply one, which
 * makes this a feature as much as a safeguard: an application that just echoes
 * works unchanged.
 *
 * The residual risk is a write made directly to the `STDOUT` handle
 * (`fwrite(STDOUT, …)`, `file_put_contents('php://stdout', …)`), which no
 * output buffer can intercept. That is not how PHP or any framework produces
 * output, and the consequence is a checksum mismatch that the server reports
 * clearly rather than a silent corruption — see [`Header`].
 *
 * ### 2. Graceful shutdown by construction
 *
 * When the server stops the worker it closes the child's stdin. This loop reads
 * that as EOF and returns from `run()`, so the worker exits on its own. There is
 * no signal to catch and no flag to poll, which is why the server only needs to
 * kill the process after a grace period rather than to ask nicely twice.
 */
final class Worker
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
     * The build of this SDK: the version its checkout is at, the commit it is
     * on, or both — whatever the surrounding install advertises.
     *
     * Deliberately *not* a version constant. During development there is no
     * release to name — `main` is the artifact — and a literal would be one more
     * thing to keep true by hand each time anything changed. Asking the install
     * answers the question that actually matters ("which exact build is this
     * worker running?") and cannot go stale, because nothing is written down to
     * go stale.
     *
     * `Composer\InstalledVersions` is an optional dependency and a static array
     * lookup, not a subprocess: null for a worker started by a bare
     * `php worker.php`, which never went through Composer. It is also null for a
     * path repository, which carries no version of its own — so the commit
     * reference is the part that survives in a development checkout, and is the
     * part that identifies the build.
     *
     * `'unknown'` is the honest answer when neither is available. It is not a
     * failure: a worker that cannot say which build it is can still serve
     * requests, and it should not refuse to start over a diagnostic.
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
     * What Composer knows about *this* package, or nulls when it is not installed.
     *
     * Asked by name rather than through `getRootPackage()`, because the root
     * package is the **consuming application**: using it would make the SDK
     * report its host's build under the worker's own name, which is a plausible
     * looking answer and the wrong one.
     *
     * Everything is guarded because this must never throw. A worker started by a
     * bare `php worker.php` has no Composer at all, and a diagnostic is not worth
     * a failed handshake.
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

    /** The output-buffer nesting depth we found when the capture started. */
    private int $captureLevel = 0;

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
     * @param resource $in  the server's end: frames to read
     * @param resource $out stdout: frames to write
     * @param resource $err stderr: diagnostics, never part of the protocol
     */
    public function __construct($in, $out, $err)
    {
        $this->in = $in;
        $this->out = $out;
        $this->err = $err;
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
     * How long to wait for the server to accept the connection.
     *
     * Generous on purpose: the server binds its listener *before* spawning the worker, so
     * the connection is already waiting when this runs. The timeout is here to turn "the
     * server is not there" into an error rather than a hang.
     */
    private const CONNECT_TIMEOUT = 10.0;

    /**
     * Create a worker bound to whichever transport the server asked for.
     *
     * This is what a worker file should call. With `VOLUCER_WORKER_SOCKET` set, the
     * protocol runs over a loopback socket; with it unset, over stdin/stdout exactly as
     * [`Worker::create`] does. Nothing else in a worker needs to know which one it got.
     *
     * The option exists for one measured reason. On Windows, PHP's stream layer takes ~8 ms
     * median (15 ms at p95) to return from a blocking read on a *pipe*, while the same read
     * on a *socket* returns in ~0.09 ms. A Rust process reading the identical pipe returns
     * in 0.010 ms, so the cost is PHP-on-Windows rather than the pipe. See the `transport`
     * setting in the volucer configuration, and plan hazard 46.
     *
     * A worker that cannot reach the address exits non-zero rather than falling back to
     * stdio. Falling back would be worse than failing: the server is waiting for a
     * connection, so a worker that quietly reads stdin instead would present as a startup
     * timeout with no clue as to the cause.
     */
    public static function connect(): self
    {
        $address = self::socketAddress();

        if ($address === null) {
            return self::create();
        }

        // `@` because the warning duplicates the message below, which is also written to
        // stderr — and on the socket transport stderr is the only channel the server sees.
        $stream = @stream_socket_client(
            'tcp://' . $address,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
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

    /**
     * The address the server asked to be reached at, or `null` for stdio.
     *
     * Split out from [`Worker::connect`] so the decision is testable without a socket: the
     * interesting cases are all *absence* — unset, set to an empty string, set to a string
     * of whitespace — and each of them must mean stdio rather than "connect to nowhere".
     * An empty value is what a shell produces for `VOLUCER_WORKER_SOCKET=`, which is a
     * thing an operator will type while debugging.
     */
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
     * Handshake, then serve requests until the server closes stdin.
     *
     * `$handler` receives the [`Request`] and this worker, and may return:
     *
     * - a [`Response`] — sent as given;
     * - a `string` — sent as a 200 `text/html` body;
     * - `null` — the captured output becomes the body (the `echo` case).
     *
     * Returning null is what makes a plain PHP script work as a worker: whatever
     * it echoed is the response.
     *
     * @param callable(Request, self): (Response|string|null) $handler
     */
    public function run(callable $handler): void
    {
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
                    $this->serve($header, $payload, $handler);
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
     * Handshake, then handle jobs until the server closes stdin.
     *
     * The consumer side, and the shape an application sees is one line:
     *
     * ```php
     * $worker = Worker::connect();
     * $worker->consume(function (Job $job, Worker $worker): string|null {
     *     return null;   // done; `fail($reason)` or `requeue()` to say otherwise
     * });
     * ```
     *
     * ## What the handler returns
     *
     * - `null` — **acknowledge.** The job is handled; the server removes the reservation.
     * - a `string` — **reject**, with that string as the reason. The job is *acknowledged*, not
     *   requeued, because a job an application has decided against is not a job that is waiting.
     * - `false` — **requeue.** Not handled, and it should be handed out again.
     *
     * A `bool false` rather than a `Job::requeue()` method, because a handler that *throws* has also
     * not handled the job and the two must not be confused: a throw means the application has a bug, a
     * `false` means this job should be tried again. The second is a decision and the first is not.
     *
     * ## A throwing handler requeues, and that is the safe direction
     *
     * A consumer that crashes must not lose the job, and an application that wanted a crashing job
     * *dropped* can catch its own exception and return a string — which is the visible, deliberate act
     * this default pushes an application towards. The opposite default (acknowledge, so a poison job
     * cannot loop for ever) is what RoadRunner's `fail()` without `requeue` does, and it silently
     * discards work when a database is briefly unreachable; the loop it guards against is bounded by
     * the `attempts` header the server puts on every job, which the application can read and act on.
     *
     * ## No response body, but output is still captured
     *
     * `run()` captures the application's `echo` because it becomes a response body. A job has no
     * response body — so an application's `echo` is a *diagnostic*, and this captures it and forwards it
     * through `emitLog` rather than leaving it on the protocol channel. That is rule 1 of this protocol
     * and it applies here exactly as it does to a request worker: an unbuffered write to stdout lands
     * inside a frame and desynchronises the stream for good. What differs is only where the captured
     * bytes end up — in a `LOG` frame, which the server renders to its own error log.
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
                    // The mirror of the `JOB` arm in `run()`: a request on a consumer has nowhere to go.
                    // Answered with an `ERROR` rather than only diagnosed, and that is not politeness:
                    // the server's request path has a 30-second deadline and its answer to a missed one
                    // is to kill the worker. A silent consumer would therefore turn a one-line
                    // configuration mistake into a worker killed once a minute, with nothing in the log
                    // saying why. A non-recoverable `ERROR` is reported as a protocol failure *without*
                    // discarding the worker, which is the honest outcome: the stream is aligned, the
                    // deployment is wrong.
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

    /**
     * Read one job, call the handler, and answer with what it decided.
     *
     * The whole outcome policy is the match at the bottom, and it is deliberately the only place it
     * exists on this side — see [`Worker::consume`] for why each arm is what it is.
     */
    private function handleJob(Header $header, string $payload): void
    {
        $this->requestId = $header->requestId;
        $this->keepAlive = true;

        $metadata = $this->decodeJson($payload, 'a JOB header');
        $body = $this->readRequestBody($header->requestId, $this->intField($metadata, 'body_len'));

        $job = new Job(
            pipeline: $this->stringField($metadata, 'pipeline'),
            id: $this->stringField($metadata, 'id'),
            task: $this->stringField($metadata, 'task'),
            headers: $this->headersToMap($this->pairsField($metadata, 'headers')),
            attempts: $this->intField($metadata, 'attempts'),
            payload: $body,
        );

        $started = hrtime(true);

        // **Captured, and then written to stderr — not left to reach stdout.** A job has no response
        // body, so echoing is a diagnostic rather than a result, and an application's `echo` on an
        // unbuffered write to the protocol channel would land *inside* a frame and corrupt the stream
        // for good. That is rule 1 of this protocol, and it applies to a consumer exactly as it does to
        // a request worker: what differs is only where the captured bytes go.
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

            // Requeued, not acknowledged: a consumer that crashed must not lose the job. See
            // `consume` for why this is the safe direction and what bounds the loop.
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

    /**
     * Tell the server a job cannot be run, without running it.
     *
     * A worker handed a `JOB` it has no handler for — a request worker the deployment pointed at a
     * queue — must still answer it, because a dropped job leaves the server's poller waiting until the
     * exchange's deadline and a deadline is a kill. Rejected rather than requeued: the configuration is
     * wrong, and handing the same job to the same misconfigured worker again is a loop rather than a
     * retry.
     *
     * The body is read and discarded rather than ignored, and that is not tidiness: the frames are
     * already on the wire, and a reader that stopped after the head would read the job's payload as the
     * next frame header.
     */
    private function refuseJob(Header $header, string $payload): void
    {
        $metadata = $this->decodeJson($payload, 'a JOB header');
        $this->readRequestBody($header->requestId, $this->intField($metadata, 'body_len'));

        $this->sendJson(MessageType::ACK, $header->requestId, [
            'outcome' => 'reject',
            'reason' => 'this worker serves requests, not jobs',
            'peak_memory_bytes' => 0,
            'duration_us' => 0,
            'keep_alive' => true,
        ]);
    }

    /**
     * Serve one request: read its body, call the handler, write the response.
     *
     * @param callable(Request, self): (Response|string|null) $handler
     */
    private function serve(Header $header, string $payload, callable $handler): void
    {
        $this->requestId = $header->requestId;
        $this->keepAlive = true;

        $metadata = $this->decodeJson($payload, 'a REQUEST header');
        $body = $this->readRequestBody($header->requestId, $this->intField($metadata, 'body_len'));

        $started = hrtime(true);
        $this->beginCapture();

        try {
            $request = $this->buildRequest($metadata, $body);
            $result = $handler($request, $this);
            $captured = $this->endCapture();
            $response = $this->normalise($result, $captured);
            $appStatus = 0;
        } catch (\Throwable $exception) {
            // The handler left its buffer open or unwound ours, so recover
            // whatever is there before reporting the failure.
            $this->endCapture();

            $this->diagnose(sprintf(
                'uncaught %s: %s in %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            $response = Response::text("Internal Server Error\n", 500);
            $appStatus = 1;
        }

        $durationUs = (int) ((hrtime(true) - $started) / 1000);

        $this->respond($response, $appStatus, $durationUs);
    }

    /**
     * Write a response: head, body chunks, then the terminal status.
     *
     * Private on purpose. The protocol puts `body_len` in the RESPONSE head, so
     * a response has to be complete before it starts — there is no way to stream
     * one. That makes "return a response" the only sane shape for a handler, and
     * a public `respond()` would only offer a way to send two of them. A second
     * head is a protocol error the server reports, so the footgun is removed
     * rather than documented.
     *
     * `body_len` is declared here and every byte is then sent, because the
     * server treats it as authoritative *and* requires the final body frame to
     * carry LAST. Declaring a length the body does not match is exactly the bug
     * those two checks exist to catch.
     *
     * The reported figure is cleared afterwards, so that each `END` describes one
     * request. See [`peakMemoryBytes`] for why that matters.
     */
    private function respond(Response $response, int $appStatus, int $durationUs): void
    {
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
     * The peak resident set reached since the last request, in bytes.
     *
     * `memory_get_peak_usage(true)` is a high-water mark that begins at process
     * start and never falls, so a worker that peaked once — during bootstrap, or
     * during any single large request — would report a figure above a configured
     * `memory_limit_mb` on *every* request thereafter, and the server's threshold
     * would recycle it after each one. Correct in the sense that the peak was
     * real, but useless in practice: it turns a leak detector into a per-request
     * application boot.
     *
     * `memory_reset_peak_usage()` re-baselines the high-water mark to the current
     * usage, so the number in the `END` frame measures the request that just
     * finished. The reset happens *after* the figure is read. It arrived in PHP
     * 8.2, which is also the SDK's stated minimum, so the `function_exists` guard
     * below is not a supported path: it exists so that running on something older
     * degrades to a monotonic figure — the old, over-eager behaviour — instead of
     * a fatal error on the first request.
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
     * Emit a metric sample without touching stdout.
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
     * Register what answers a `CALL` the server makes while a request is in flight.
     *
     * The callable receives the decoded call as an array with `name` and `params`, and may return:
     *
     * - a value, which becomes the `RETURN`'s result;
     * - `['ok' => false, 'error' => 'why']`, to refuse it with a definite answer;
     * - `['ok' => false, 'unknown' => true, 'error' => 'why']`, when the call **may have taken
     *   effect** — a lock acquired before the reply was lost, a write sent and not acknowledged.
     *   The server treats that differently from a refusal, because a caller that retries a refusal
     *   is safe and a caller that retries an unknown state duplicates a side effect.
     *
     * A throw from the callable is caught and turned into a refused call, so a bug in an application's
     * handler cannot kill the worker: the loop is mid-request, and a `RETURN` is still owed.
     *
     * Optional. Without it, a `CALL` is refused by name rather than ignored — see
     * [`Worker::$callHandler`] for why silence is not an option.
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
     * Ask the server to do something, from inside a request handler.
     *
     * The one method that sends a frame to the server *unprompted* and then waits for its answer, and
     * it is what the whole call plane exists for: `kv.get`, `lock.acquire`, `jobs.push`,
     * `metrics.observe`, and the `workers.*` / `config.*` control methods.
     *
     * **Only callable while a request is in flight.** The answer arrives on the same channel as the
     * response, and the server's reader for this worker is parked on `recv()` between requests — so a
     * call made outside a handler has nobody listening for the `RETURN` and would block until the
     * deadline. It raises rather than hanging, because a hang here is a worker killed for no visible
     * reason.
     *
     * @param string $name   `"<namespace>.<verb>"`, e.g. `kv.get`.
     * @param array<string, mixed> $params method-specific arguments.
     *
     * @return mixed the call's result, or null when the server refused it; see {@see Worker::lastCallError()}.
     *
     * @throws ProtocolException if the stream desynchronised, or a call was made outside a request.
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
     * Read frames until the answer to `$id` arrives, answering anything else that comes first.
     *
     * **A small dispatch loop, not a nested pump.** Between the call and its answer the server may send
     * a `PING`, a `CONTROL`, or another `CALL` — all three are ordinary and none of them is this call's
     * answer, so each is handled exactly as the main loop handles it and the read resumes. Nothing here
     * re-enters the request path, which is what keeps "one request in flight" true while a call is
     * outstanding.
     *
     * **Blocking, with no timeout of its own**, and that is a decision rather than an omission. The
     * protocol guarantees that every call is answered — a refusal is a `RETURN` like any other, and the
     * server's side of that rule is that a call it cannot serve is still answered by name. So a worker
     * waiting here is waiting for a frame that is already on its way. A timeout would need
     * `stream_select`, which is meaningless on a Windows pipe, and it would put the deadline in the
     * wrong place: the server is the side that knows how long a backend may take, and §4.2 of
     * `docs/plugins.md` puts the per-call deadline *inside the handler* for exactly that reason. Reading
     * as the main loop reads also means a timeout cannot leave half a frame in a buffer.
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
     */
    private function handleControl(string $payload): void
    {
        $control = $this->decodeJson($payload, 'a CONTROL');
        $kind = is_string($control['kind'] ?? null) ? $control['kind'] : '';

        $this->send(new Frame(MessageType::CONTROL_ACK, self::UNSOLICITED));

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
                $this->emitMetric('worker.peak_memory_bytes', (float) $this->peakMemoryBytes(), 'bytes');
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

    // ------------------------------------------------------------ request side

    /**
     * Read the request body from the BODY frames that follow a REQUEST.
     *
     * Stops at `body_len` bytes, because the declared length is authoritative.
     * A frame flagged LAST that arrives early means the sender and this reader
     * disagree about the length, which is worth failing on rather than papering
     * over: the rest of the stream cannot be trusted after that.
     */
    private function readRequestBody(int $requestId, int $bodyLen): string
    {
        if ($bodyLen === 0) {
            return '';
        }

        $body = '';
        while (strlen($body) < $bodyLen) {
            $header = $this->readHeader();
            if ($header === null) {
                throw new ProtocolException(sprintf(
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

            $body .= $payload;

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
     * Read one frame header, or null at a clean end of stream.
     *
     * `stream_get_contents($h, 16)` is doing the work here, and that is
     * deliberate: it blocks until it has 16 bytes or the stream ends, handling
     * partial reads itself. Hand-rolling an `fread` loop is the bug-prone part
     * of every pipe protocol, and this call is why the SDK does not have one.
     */
    private function readHeader(): ?Header
    {
        $bytes = stream_get_contents($this->in, Header::HEADER_LEN);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return Header::decode($bytes);
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

        $payload = stream_get_contents($this->in, $header->payloadLen);

        if ($payload === false || strlen($payload) !== $header->payloadLen) {
            throw new ProtocolException(sprintf(
                'a frame declared %d payload bytes but the stream ended after %d',
                $header->payloadLen,
                $payload === false ? 0 : strlen($payload),
            ));
        }

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
    private function sendJson(int $messageType, int $requestId, mixed $value): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->send(new Frame($messageType, $requestId, $encoded));
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
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
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
