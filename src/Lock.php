<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Resource locks, as the server holds them.
 *
 * One instance per worker, obtained from {@see Worker::lock()}. The shape follows RoadRunner's own
 * `RoadRunner\Lock\Lock` closely enough that an application written against it recognises the methods,
 * with the two differences that are the point of the volucer design spelled out below.
 *
 * ## Read this before using a lock for anything that matters
 *
 * A lock here has a **lease** and **no fencing token**. A worker that stalls past its lease — a pause,
 * a swap storm, a debugger — still believes it holds the lock while the server has handed it to
 * somebody else, and two processes are then inside the section it was meant to guard. No amount of
 * care on the server side fixes that, because the late writer is late *precisely because it was not
 * running*. RoadRunner's locks have the same hole.
 *
 * So a lock is for **coordinating work**: collapsing a cache miss, keeping two workers from rendering
 * the same PDF twice, making a retry cheap. It is **not** for protecting an invariant whose violation
 * corrupts data. For that, use the storage's own conditional write — a unique index, a
 * compare-and-swap — and see {@see Worker::kv()}.
 *
 * ## The three answers an acquisition has, and the one you must not misread
 *
 * `acquire()` and `tryAcquire()` return `null` for contention, which is an **ordinary outcome** rather
 * than an error: nothing happened, and the caller can do something else. They raise
 * {@see ProtocolException} for a call the server could not decide — its message says the lock state is
 * unknown — and **that is not the same answer as contention**. Treating an unknown as "not held" is
 * how two processes end up in the same critical section, which is why the distinction travels all the
 * way from the backend to this method instead of being flattened into a `false`.
 *
 * ## The lease semantics worth knowing, because neither is the obvious reading
 *
 * - **`$ttl = 0` means held until released**, not "expires immediately". It is the opposite of what a
 *   zero TTL means to a KV value, and it is what RoadRunner has always meant by it.
 * - **`updateTtl()` replaces the remaining lease rather than extending it.** A caller reading it as
 *   "another N seconds from now" can be released early.
 * - **An id that already holds the resource is refused**, in either mode, with an exception naming
 *   `updateTtl`. (RoadRunner's Redis backend lets the same token re-obtain and silently moves the
 *   deadline. With `updateTtl()` in the API there is no reason for a second acquisition to mean
 *   "renew".)
 */
final class Lock
{
    /** @var Worker the worker this lock plane is reached through */
    private Worker $worker;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
    }

    /**
     * Acquire an exclusive lock, waiting up to `$wait` for a conflicting one to be released.
     *
     * @param string   $resource what is being locked.
     * @param int      $ttl      microseconds to hold it for; **0 means until released**.
     * @param int      $wait     microseconds to keep trying; 0 makes exactly one attempt.
     * @param string|null $id    reuse an id, or null to be handed a fresh one.
     *
     * @return string|null the **id** on success — keep it, it is the only thing that can release the
     *                     lock — or `null` if the resource is held by somebody else or `$wait` ran out.
     *
     * @throws ProtocolException if the server refused the call, or could not decide its outcome. The
     *                           second case is not contention; see the class documentation.
     */
    public function acquire(string $resource, int $ttl = 0, int $wait = 0, ?string $id = null): ?string
    {
        return $this->attempt('acquire', $resource, $ttl, $wait, $id);
    }

    /**
     * Acquire a **shared** lock: other readers may hold it too, and an exclusive holder excludes it.
     *
     * @see acquire for the arguments and the return value.
     */
    public function acquireShared(string $resource, int $ttl = 0, int $wait = 0, ?string $id = null): ?string
    {
        return $this->attempt('acquire', $resource, $ttl, $wait, $id, exclusive: false);
    }

    /**
     * Make one attempt with no waiting at all.
     *
     * Equivalent to `acquire($resource, $ttl, 0, $id)`, and written as its own method because that is
     * the intent a caller usually has and because the server has a method for it: `wait = 0` on a
     * *contended* lock still costs the server a lookup, and saying "do not wait" is saying "one
     * attempt, not a deadline that happens to be now".
     */
    public function tryAcquire(string $resource, int $ttl = 0, ?string $id = null): ?string
    {
        return $this->attempt('try_acquire', $resource, $ttl, 0, $id);
    }

    /**
     * The shared form of {@see tryAcquire}.
     */
    public function tryAcquireShared(string $resource, int $ttl = 0, ?string $id = null): ?string
    {
        return $this->attempt('try_acquire', $resource, $ttl, 0, $id, exclusive: false);
    }

    /**
     * Release the lock this id holds, answering whether there was one.
     *
     * `false` means the id did not hold the resource — the lease expired, or something released it —
     * and that is an **answer rather than an error**, because "my lock is already gone" is not
     * something a `finally` block wants to throw for.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function release(string $resource, string $id): bool
    {
        return (bool) ($this->ask('release', ['resource' => $resource, 'id' => $id])['released'] ?? false);
    }

    /**
     * Release **every** lock on the resource, whoever holds them.
     *
     * The escape hatch for a resource wedged by a worker that died. It cannot be scoped by id and is
     * not, which is why the server puts it behind the same capability that grants locking at all.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function forceRelease(string $resource): bool
    {
        return (bool) ($this->ask('release_all', ['resource' => $resource])['released'] ?? false);
    }

    /**
     * Whether anything holds the resource.
     *
     * This is the method to call when an acquisition raised with an unknown outcome: it is the
     * question that resolves it, and the server's answer to it is derived from the same code that
     * reports holders, so the two cannot disagree.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function exists(string $resource): bool
    {
        return (bool) ($this->ask('exists', ['resource' => $resource])['exists'] ?? false);
    }

    /**
     * Every holder of the resource, with what is left of each lease.
     *
     * A list of `['id' => string, 'mode' => 'exclusive'|'shared', 'remaining_ms' => int|null]`, where
     * `null` is a lease that does not expire. An expired holder is not reported, whether or not the
     * backend has got round to removing it.
     *
     * @return list<array{id: string, mode: string, remaining_ms: int|null}>
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function holders(string $resource): array
    {
        /** @var list<array{id: string, mode: string, remaining_ms: int|null}> $holders */
        $holders = $this->ask('holders', ['resource' => $resource])['holders'] ?? [];

        return is_array($holders) ? array_values($holders) : [];
    }

    /**
     * Set what is left of this id's lease, from now.
     *
     * **Replaces the deadline rather than extending it**: renewing for ten microseconds ten
     * microseconds into a lease of twenty leaves ten, not twenty. A caller that reads it as "another
     * N" can be released early.
     *
     * `false` means the id held nothing, which is the answer to act on — the section being protected
     * is no longer protected — and not an error, because the call itself succeeded.
     *
     * @param int $ttl microseconds; **0 means held until released**.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function updateTtl(string $resource, string $id, int $ttl): bool
    {
        return (bool) ($this->ask('update_ttl', [
            'resource' => $resource,
            'id' => $id,
            'ttl' => $ttl,
        ])['updated'] ?? false);
    }

    /**
     * The whole acquisition, shared by the four entry points above.
     *
     * The id comes back in the answer even when nothing was acquired, and it is deliberately *not*
     * remembered on this object: the returned id is the caller's to keep, and a client that cached the
     * last one would hand a second `acquire()` an id that already holds the resource — which the
     * server refuses, and would look from here like a mysterious failure.
     *
     * @throws ProtocolException if the server refused the call or could not decide it.
     */
    private function attempt(
        string $verb,
        string $resource,
        int $ttl,
        int $wait,
        ?string $id,
        bool $exclusive = true,
    ): ?string {
        if ($resource === '') {
            throw new \InvalidArgumentException('a lock resource must not be empty; the server refuses one');
        }

        $params = ['resource' => $resource, 'ttl' => $ttl, 'mode' => $exclusive ? 'exclusive' : 'shared'];
        if ($verb === 'acquire') {
            $params['wait'] = $wait;
        }
        if ($id !== null && $id !== '') {
            $params['id'] = $id;
        }

        $answer = $this->ask($verb, $params);
        if (!($answer['acquired'] ?? false)) {
            return null;
        }

        $held = $answer['id'] ?? null;
        if (!is_string($held) || $held === '') {
            // Unreachable against this server, and checked because the alternative is returning `null`
            // for a lock the server has just granted — which the caller reads as contention and
            // answers by *not* entering the section. A silent hole in an exclusion is worth one
            // comparison.
            throw new ProtocolException('the server granted a lock without naming the id that holds it');
        }

        return $held;
    }

    /**
     * Make a lock call, raising on anything the server did not serve.
     *
     * The same reasoning as `Kv::ask()`: a refused call leaves `Worker::call` returning `null`, and
     * `null` from `exists()` would read as "nothing holds it" — which, on the one method whose whole
     * purpose is resolving an unknown state, is the worst possible answer to invent.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws ProtocolException if the server refused the call.
     */
    private function ask(string $verb, array $params): array
    {
        $name = 'lock.' . $verb;
        $answer = $this->worker->call($name, $params);

        $error = $this->worker->lastCallError();
        if ($error !== null) {
            throw new ProtocolException(sprintf(
                'the server refused %s: %s%s',
                $name,
                $error,
                $this->worker->lastCallWasUnknown()
                    ? ' (and the lock state is unknown, so ask exists() before deciding what it means)'
                    : '',
            ));
        }

        return is_array($answer) ? $answer : [];
    }
}
