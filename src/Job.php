<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A job as the server sent it, for a worker in consumer mode.
 *
 * Deliberately a plain value object for the same reason [`Request`] is: the SDK is a transport, not
 * a framework, so the shape an application sees must not commit it to anyone's interfaces.
 *
 * ## What the server hands over, and what it does not
 *
 * The server knows three things about a job that the application does not: which pipeline it came
 * from, which driver id it has, and how many times it has been reserved. All three are here, because
 * an application's retry policy is built from the last one and its logs are read by the second.
 *
 * What is *not* here is the payload's meaning. The server never parses it and never will — see
 * `internal/plugins.md` §6.4 — so [`Job::$payload`] is the bytes that were pushed, and how they were
 * serialized is between the producer and the handler. `json_decode` is the common case and is not
 * this class's business.
 *
 * ## The attempt count is the retry policy
 *
 * [`Job::$attempts`] counts **reservations**, starting at 1 for the first. It is incremented by the
 * driver on the way out of a reserve and never on a failure, which is what makes it the number a
 * retry policy wants: a worker killed mid-job never runs its own failure path, so a count that
 * included failures would miss exactly the crashes worth counting.
 *
 * The server imposes no limit, no backoff and no dead-lettering, so a handler that wants them writes
 * them:
 *
 * ```php
 * $worker->consume(function (Job $job): string|null {
 *     if ($job->attempts > 3) {
 *         return "gave up after {$job->attempts} attempts";   // rejected, not requeued
 *     }
 *     return sendEmail($job->json()) ? null : false;          // acknowledged, or requeued
 * });
 * ```
 */
final class Job
{
    /**
     * The pipeline this job was reserved from.
     *
     * The name the producer pushed to, which is what a handler switches on when one consumer serves
     * several queues — and what a log line needs to be findable at all.
     */
    public readonly string $pipeline;

    /**
     * The driver's id for this job, unique within its pipeline.
     *
     * Worth having for a different reason than the server's own reservation id, which is deliberately
     * **not** exposed: this is the id a producer was told when it pushed, so it is the one an operator
     * can search for in a dashboard. Acknowledging is the server's business, and it does that from the
     * frame's correlation id — see `docs/worker-protocol.md`.
     */
    public readonly string $id;

    /**
     * The application's task name: `App\Jobs\SendEmail`, or whatever the producer wrote.
     *
     * Opaque to the server. A handler that serves several tasks switches on this; RoadRunner's
     * `$task->getName()` is the same idea, spelled the same way.
     */
    public readonly string $task;

    /**
     * Application metadata, as the producer sent it.
     *
     * String values only, PSR-7-shaped, and looked up with [`Job::header`] because the server
     * case-folds nothing on this side.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * How many times this job has been reserved, counting this one.
     *
     * Starts at 1. See the class documentation for why it is the retry policy and why the server does
     * not supply one.
     */
    public readonly int $attempts;

    /**
     * The application's payload, exactly as it was pushed.
     *
     * Not decoded, not validated, and not this SDK's business: a queue's payload format belongs to the
     * application, which is the whole reason the server stores bytes.
     */
    public readonly string $payload;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $pipeline,
        string $id,
        string $task,
        array $headers,
        int $attempts,
        string $payload,
    ) {
        $this->pipeline = $pipeline;
        $this->id = $id;
        $this->task = $task;
        $this->headers = $headers;
        $this->attempts = $attempts;
        $this->payload = $payload;
    }

    /**
     * Look up a header case-insensitively.
     *
     * Case-insensitive because these are PSR-7-shaped headers and an application will reach for
     * `TraceParent` while the producer wrote `traceparent`; the server's own `JobHeader::header` does
     * the same fold, so both ends of one protocol agree about what a name means.
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

    /**
     * The payload decoded as JSON, or null when it is not JSON.
     *
     * A convenience, not a contract: a producer that pushed a serialized object, a CSV row or a
     * protobuf reads [`Job::$payload`] instead. Returning null rather than throwing, because the
     * server has no opinion about the payload and neither should this — a handler that *wants* to
     * insist on JSON can decode it itself and see the exception.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        if ($this->payload === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether the payload's `traceparent` header says this job continues a trace.
     *
     * The server promotes `traceparent` out of the header bag into a field of its own on the wire, and
     * this reads it back from the header bag anyway — because the *producer* wrote it there through
     * the same `jobs.push` call an application already makes, and an application should not have to
     * know which fields the transport promotes.
     */
    public function traceParent(): ?string
    {
        return $this->header('traceparent');
    }
}
