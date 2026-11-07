<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A job reserved for a consumer. The payload is opaque; applications define its format.
 *
 * Attempts count reservations, starting at 1, and can be used to implement retry policies. The server
 * does not limit or delay retries.
 *
 * ```php
 * $worker->consume(function (Job $job): string|false|null {
 *     if ($job->attempts > 3) {
 *         return "gave up after {$job->attempts} attempts";
 *     }
 *     return sendEmail($job->json()) ? null : false;
 * });
 * ```
 */
final class Job
{
    /**
     * Pipeline this job was reserved from.
     */
    public readonly string $pipeline;

    /**
     * Driver-assigned job id, unique within its pipeline.
     */
    public readonly string $id;

    /**
     * Application-defined task name.
     */
    public readonly string $task;

    /**
     * Application metadata. Header names are looked up case-insensitively with {@see Job::header()}.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * Reservation count, including this one; starts at 1.
     */
    public readonly int $attempts;

    /**
     * Raw application payload, exactly as pushed.
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
     * Look up a header without regard to name casing.
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
     * Decode the payload as a JSON object or array, or return null if it is empty, invalid, or not an
     * object or array.
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
     * Return the `traceparent` header, if present.
     */
    public function traceParent(): ?string
    {
        return $this->header('traceparent');
    }
}
