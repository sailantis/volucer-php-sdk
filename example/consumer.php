<?php

declare(strict_types=1);

/**
 * A minimal volucer job consumer.
 *
 * Run it directly — there is nothing to install:
 *
 *     php example/consumer.php
 *
 * It will print a handshake banner to stderr and then wait, doing nothing, because a consumer speaks
 * the protocol over stdin/stdout rather than doing anything on its own. To drive it you need the
 * server: `volucer serve` with a `[jobs]` table whose `[jobs.pool] command` names this file.
 *
 * ## What this file is
 *
 * The consumer side of the same protocol `worker.php` speaks, and the whole difference between the two
 * is `consume()` instead of `run()`. A worker serving HTTP is a process that reboots its per-request
 * state between requests; a consumer is a process that boots once and keeps it — a database
 * connection, a container, a warm cache — across every job it handles. That is why they are two
 * methods and two frame types rather than one with a flag: the reset is the thing an application
 * cannot get wrong, and the protocol says which one it is before the application sees anything.
 *
 * ## What the handler returns
 *
 * - `null` — acknowledge. Handled; forget it.
 * - a `string` — reject, with that reason. **Acknowledged, not requeued**: a job an application has
 *   decided against is not a job that is waiting, and requeuing it would bring it back for ever.
 * - `false` — requeue. Not handled, and it should be handed out again.
 *
 * A handler that *throws* is requeued, because a consumer that crashed must not lose the job. An
 * application that wants a crashing job dropped catches its own exception and returns a string, which
 * is the deliberate, visible act.
 *
 * ## The retry policy is the application's
 *
 * `$job->attempts` counts reservations, starting at 1, and the server imposes no limit, no backoff and
 * no dead-lettering — see `docs/plugins.md` §6.4 of `sailantis/volucer` for why. So the policy is
 * written here, in the one place an application can see it:
 *
 * ```php
 * if ($job->attempts > 3) {
 *     return "gave up after {$job->attempts} attempts";
 * }
 * ```
 */

require __DIR__ . '/../autoload.php';

use Volucer\Job;
use Volucer\Worker;

$worker = Worker::connect();

$worker->consume(static function (Job $job, Worker $worker): string|bool|null {
    // The job's own logs. Captured and forwarded as a LOG frame, never written to stdout — stdout is
    // the protocol channel, and a stray byte there desynchronises the stream for good.
    echo "[", date('H:i:s'), "] {$job->pipeline}/{$job->task} #{$job->attempts}", PHP_EOL;

    // Which pipeline it came from, when one consumer serves several. A queue full of unrecognised
    // tasks is the thing an operator needs a reason for, so the default arm says so by name rather
    // than failing silently.
    switch ($job->task) {
        case 'App\\Jobs\\SendEmail':
            $payload = $job->json() ?? [];
            $worker->emitMetric('example.jobs.sent', 1.0, 'count');
            $worker->emitLog('would send to ' . ($payload['to'] ?? '(nobody)'));

            return null;

        case 'App\\Jobs\\AlwaysFails':
            // Rejected rather than requeued, because this demo task is *designed* to fail and a
            // requeue would spin for ever. A real application would be checking `$job->attempts`
            // first — see the class documentation.
            return 'this task always fails, and this example will not retry it';

        case 'App\\Jobs\\RetryOnce':
            // Requeued on the first attempt and acknowledged on the second, which is the shortest
            // honest demonstration that `$job->attempts` is what it claims to be.
            return $job->attempts === 1 ? false : null;

        default:
            return "no handler for task {$job->task}";
    }
});
