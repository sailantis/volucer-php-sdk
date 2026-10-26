<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A queue, from the producer's side.
 *
 * One instance per pipeline, obtained from {@see Worker::queue()}. The shape follows
 * `Spiral\RoadRunner\Jobs\Jobs` closely enough that an application written against it recognises
 * `create()`, `dispatch()` and `push()`, with the differences that matter stated here rather than
 * discovered:
 *
 * - **No `Options` object.** RoadRunner's `Options` carries `autoAck`, `delay` and driver-specific keys
 *   in one mutable thing; this client has none of them, because the server has none: `autoAck` is the
 *   consumer's business, `delay` needs a driver with a scheduler and no driver
 *   here has one, and a driver-specific option would make this class depend on which driver a deployment
 *   chose.
 * - **The payload is a string, and it is the caller's to serialize.** Exactly as RoadRunner's is: "The
 *   payload must be any string. You need to serialize the data yourself." The server never parses it, so
 *   an application can compress or encrypt what it queues.
 * - **Headers are strings.** A header whose value is not a string is refused here rather than by the
 *   server, because the failure is a *local* one and a round trip to learn that is a round trip wasted.
 *
 * ## Producing only
 *
 * There is no `reserve()`, `ack()` or `nack()` here, and the reason is the design rather than an
 * omission. A reservation's lease starts ticking the moment a job is handed out, and this class is used
 * from inside a *request* — so a job reserved here would be held by a worker that is already busy with
 * something else while its lease ran down. A consumer is a different process, started from
 * `[jobs.pool]`, and it reserves through its own channel.
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

    /**
     * The pipeline's name, as configured.
     */
    public function pipeline(): string
    {
        return $this->pipeline;
    }

    /**
     * Put a job in the pipeline, answering the id the server assigned.
     *
     * The id is the server's rather than the caller's — it has to be unique across processes that never
     * talk to each other — and it is worth keeping: it is what a consumer logs and what an operator
     * searches for in `jobs.size` output and in the driver.
     *
     * @param string               $task    the application's task name, e.g. `App\Jobs\SendEmail`.
     * @param string               $payload the application's own bytes; never parsed here or by the server.
     * @param array<string,string> $headers string metadata, e.g. `traceparent`.
     *
     * @throws ProtocolException if the server refused the push. **A refused push is a failure, never an
     *                           unknown**: a push either landed in the pipeline or it did not.
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
            // Base64, like a KV value: a JSON string cannot carry a byte above 0x7f, and an application
            // that compresses or encrypts what it queues has such bytes constantly.
            'payload' => base64_encode($payload),
            'headers' => $headers === [] ? new \stdClass() : $headers,
        ]);

        $id = $answer['id'] ?? null;
        if (!is_string($id) || $id === '') {
            // Unreachable against this server, and checked because the alternative is returning an empty
            // string for a job that was pushed — which an application would log as "the queue is broken"
            // on a call that succeeded.
            throw new ProtocolException('the server accepted a push without naming the job');
        }

        return $id;
    }

    /**
     * Put several jobs in the pipeline, answering the ids that were accepted.
     *
     * **A batch is not atomic, and this is honest about it**: a batch whose third push fails has already
     * pushed two, and reporting that as a total failure would make a caller's retry duplicate them. So a
     * partial batch **throws**, with the ids that made it in the message, and the caller decides whether
     * to retry the tail. That is the opposite of `push`'s single-job answer, deliberately: with one job
     * there is nothing to be partial about.
     *
     * @param list<array{task: string, payload?: string, headers?: array<string,string>}> $jobs
     *
     * @return list<string> the ids, in the order the jobs were given.
     *
     * @throws ProtocolException if any push failed; the message carries the ids that were accepted.
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
     * How many jobs are ready, and how many are held by a consumer.
     *
     * Both numbers, because the pair is what an operator reads: a pipeline whose ready count grows while
     * its in-flight count stays flat is a consumer that is stuck, and neither number says that alone.
     *
     * @return array{size: int, in_flight: int}
     *
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
     * Every pipeline this deployment has, with its driver.
     *
     * For a diagnostic and for the refusal a typo in a pipeline name produces, which is where the list is
     * most useful — and it is *every* pipeline, not the ones this worker may push to, because a queue is
     * not scoped per backend the way a KV storage is.
     *
     * @return list<array{name: string, driver: string}>
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function pipelines(): array
    {
        /** @var list<array{name: string, driver: string}> $pipelines */
        $pipelines = $this->ask('pipelines')['pipelines'] ?? [];

        return is_array($pipelines) ? array_values($pipelines) : [];
    }

    /**
     * Make a queue call, raising on anything the server did not serve.
     *
     * The same reasoning as `Kv::ask()` and `Lock::ask()`: a refused call leaves `Worker::call` returning
     * `null`, and a push that silently did nothing would lose work with no log line anywhere.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
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
            // `"%s failed"`, not "the server refused": see `Kv::ask()` for why a call can fail before
            // it reaches the server at all.
            throw new ProtocolException(sprintf('%s failed: %s', $name, $error));
        }

        return is_array($answer) ? $answer : [];
    }
}
