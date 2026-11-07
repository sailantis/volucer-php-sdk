<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Producer client for a configured job pipeline.
 *
 * Payloads are opaque strings for the application to serialize; headers must be
 * strings. Consumers reserve and acknowledge jobs through a separate worker loop.
 */
final class Jobs
{
    /** @var CallPlane whatever answers a call: a worker's channel, or the `[rpc]` listener */
    private CallPlane $calls;

    /** @var string the pipeline's configured name */
    private string $pipeline;

    public function __construct(CallPlane $calls, string $pipeline)
    {
        if ($pipeline === '') {
            throw new \InvalidArgumentException('a queue must name a pipeline: the server refuses a push that does not say which one');
        }

        $this->calls = $calls;
        $this->pipeline = $pipeline;
    }

    /** Return the configured pipeline name. */
    public function pipeline(): string
    {
        return $this->pipeline;
    }

    /**
     * Push a job and return its server-assigned id.
     *
     * @param string $task application task name
     * @param string $payload opaque application data
     * @param array<string,string> $headers string metadata
     * @throws ProtocolException if the server refused the push
     */
    public function push(string $task, string $payload = '', array $headers = []): string
    {
        if ($task === '') {
            throw new \InvalidArgumentException('a job needs a task name, or its consumer cannot tell what it is');
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'the header %s must be a string: headers are metadata, and a structured value belongs in the payload',
                    is_string($name) ? var_export($name, true) : get_debug_type($name),
                ));
            }
        }

        $answer = $this->ask('push', [
            'pipeline' => $this->pipeline,
            'task' => $task,
            // Base64 preserves arbitrary payload bytes in JSON.
            'payload' => base64_encode($payload),
            'headers' => $headers === [] ? new \stdClass() : $headers,
        ]);

        $id = $answer['id'] ?? null;
        if (!is_string($id) || $id === '') {
            // A successful push must include its assigned id.
            throw new ProtocolException('the server accepted a push without naming the job');
        }

        return $id;
    }

    /**
     * Push a batch and return accepted ids in input order.
     *
     * Batches are not atomic; on partial failure, the exception lists accepted ids.
     *
     * @param list<array{task: string, payload?: string, headers?: array<string,string>}> $jobs
     * @return list<string>
     * @throws ProtocolException if any push failed
     */
    public function pushBatch(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $entries = [];
        foreach ($jobs as $index => $job) {
            if (!is_array($job) || !isset($job['task']) || !is_string($job['task']) || $job['task'] === '') {
                throw new \InvalidArgumentException(sprintf('the job at index %d needs a task name', $index));
            }
            $entries[] = [
                'pipeline' => $this->pipeline,
                'task' => $job['task'],
                'payload' => base64_encode((string) ($job['payload'] ?? '')),
                'headers' => ($job['headers'] ?? []) === [] ? new \stdClass() : $job['headers'],
            ];
        }

        $answer = $this->ask('push_batch', ['jobs' => $entries]);
        $ids = $answer['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];

        if (($answer['failed_index'] ?? null) !== null) {
            throw new ProtocolException(sprintf(
                'the batch stopped at index %d: %s; the %d jobs before it were pushed (%s) — retry from there',
                $answer['failed_index'],
                $answer['error'] ?? 'no reason given',
                count($ids),
                implode(', ', $ids),
            ));
        }

        return $ids;
    }

    /**
     * Return ready and in-flight job counts.
     *
     * @return array{size: int, in_flight: int}
     * @throws ProtocolException if the server refused the call.
     */
    public function size(): array
    {
        $answer = $this->ask('size', ['pipeline' => $this->pipeline]);

        return [
            'size' => (int) ($answer['size'] ?? 0),
            'in_flight' => (int) ($answer['in_flight'] ?? 0),
        ];
    }

    /**
     * List configured pipelines and their drivers.
     *
     * @return list<array{name: string, driver: string}>
     * @throws ProtocolException if the server refused the call.
     */
    public function pipelines(): array
    {
        /** @var list<array{name: string, driver: string}> $pipelines */
        $pipelines = $this->ask('pipelines')['pipelines'] ?? [];

        return is_array($pipelines) ? array_values($pipelines) : [];
    }

    /**
     * Make a queue call, raising on failure to avoid silently losing work.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ProtocolException if the server refused the call.
     */
    private function ask(string $verb, array $params = []): array
    {
        $name = 'jobs.' . $verb;
        $answer = $params === []
            ? $this->calls->call($name)
            : $this->calls->call($name, $params);

        $error = $this->calls->lastCallError();
        if ($error !== null) {
            // The call may fail before reaching the server.
            throw new ProtocolException(sprintf('%s failed: %s', $name, $error));
        }

        return is_array($answer) ? $answer : [];
    }
}
