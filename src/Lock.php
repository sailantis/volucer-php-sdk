<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Client for the server's resource locks.
 *
 * Locks are leases without fencing tokens; a stalled holder may overlap a new
 * holder after expiry. Use conditional storage writes to protect data invariants.
 * Contention returns null, while a {@see ProtocolException} may indicate an
 * unknown lock state. A TTL of zero means no expiry; `updateTtl()` replaces it.
 * An id already holding a resource cannot acquire it again.
 */
final class Lock
{
    /** @var CallPlane whatever answers a call: a worker's channel, or the `[rpc]` listener */
    private CallPlane $calls;

    public function __construct(CallPlane $calls)
    {
        $this->calls = $calls;
    }

    /**
     * Acquire an exclusive lock, optionally waiting for contention to clear.
     *
     * @param string $resource what is being locked
     * @param int $ttl lease in microseconds; zero means no expiry
     * @param int $wait wait time in microseconds; zero makes one attempt
     * @param string|null $id optional caller-provided lock id
     * @return string|null lock id, or null on contention/timeout
     * @throws ProtocolException if refused or the outcome is unknown
     */
    public function acquire(string $resource, int $ttl = 0, int $wait = 0, ?string $id = null): ?string
    {
        return $this->attempt('acquire', $resource, $ttl, $wait, $id);
    }

    /** Acquire a shared lock; multiple shared holders may coexist. @see acquire */
    public function acquireShared(string $resource, int $ttl = 0, int $wait = 0, ?string $id = null): ?string
    {
        return $this->attempt('acquire', $resource, $ttl, $wait, $id, exclusive: false);
    }

    /** Make one exclusive acquisition attempt without waiting. */
    public function tryAcquire(string $resource, int $ttl = 0, ?string $id = null): ?string
    {
        return $this->attempt('try_acquire', $resource, $ttl, 0, $id);
    }

    /** Make one shared acquisition attempt without waiting. */
    public function tryAcquireShared(string $resource, int $ttl = 0, ?string $id = null): ?string
    {
        return $this->attempt('try_acquire', $resource, $ttl, 0, $id, exclusive: false);
    }

    /**
     * Release a lock and report whether it was held.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function release(string $resource, string $id): bool
    {
        return (bool) ($this->ask('release', ['resource' => $resource, 'id' => $id])['released'] ?? false);
    }

    /**
     * Release every lock on the resource, regardless of holder.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function forceRelease(string $resource): bool
    {
        return (bool) ($this->ask('release_all', ['resource' => $resource])['released'] ?? false);
    }

    /**
     * Whether the resource has any active holders.
     *
     * Use this to investigate an acquisition with an unknown outcome.
     *
     * @throws ProtocolException if the server refused the call.
     */
    public function exists(string $resource): bool
    {
        return (bool) ($this->ask('exists', ['resource' => $resource])['exists'] ?? false);
    }

    /**
     * List active holders and their remaining lease times.
     *
     * @return list<array{id: string, mode: string, remaining_ms: int|null}>
     * @throws ProtocolException if the server refused the call.
     */
    public function holders(string $resource): array
    {
        /** @var list<array{id: string, mode: string, remaining_ms: int|null}> $holders */
        $holders = $this->ask('holders', ['resource' => $resource])['holders'] ?? [];

        return is_array($holders) ? array_values($holders) : [];
    }

    /**
     * Set the remaining lease duration from now; this replaces, not extends, the deadline.
     *
     * @param int $ttl microseconds; zero means no expiry
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
     * Perform an acquisition and return its lock id.
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
            // A granted lock without an id cannot be safely reported as contention.
            throw new ProtocolException('the server granted a lock without naming the id that holds it');
        }

        return $held;
    }

    /**
     * Call the lock service and raise on failure rather than inventing a result.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ProtocolException if the server refused the call.
     */
    private function ask(string $verb, array $params): array
    {
        $name = 'lock.' . $verb;
        $answer = $this->calls->call($name, $params);

        $error = $this->calls->lastCallError();
        if ($error !== null) {
            // The call may fail before reaching the server.
            throw new ProtocolException(sprintf(
                '%s failed: %s%s',
                $name,
                $error,
                $this->calls->lastCallWasUnknown()
                    ? ' (and the lock state is unknown, so ask exists() before deciding what it means)'
                    : '',
            ));
        }

        return is_array($answer) ? $answer : [];
    }
}
