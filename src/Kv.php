<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Key-value storage, as the server holds it.
 *
 * One instance per storage, obtained from {@see Worker::kv()}. The shape is PSR-16's, because that is
 * what an application already knows and what every other cache in PHP-land speaks — but this is not a
 * PSR-16 *implementation* and does not pretend to be one. The differences are deliberate and each is
 * worth knowing:
 *
 * - **No `getMultiple` key-returning form.** PSR-16's `getMultiple` returns an array keyed by the keys
 *   it found, which loses the distinction between a key that is absent and one whose value is `null`
 *   — and this wire format can carry both, so the distinction is preserved and the methods say so.
 * - **Values are serialized here, on the client.** That is the point of the whole design: the server
 *   holds opaque bytes and never sees your objects, so `encrypt()` below can do what it likes and the
 *   server cannot tell the difference. RoadRunner's KV serializes on the server and therefore cannot
 *   offer that.
 * - **No `setMultiple` with a per-item TTL**, because PSR-16 does not have one either and the server
 *   would have to invent a shape for it.
 *
 * ## The methods that can fail, and how
 *
 * A store that cannot serve a call raises {@see ProtocolException}, and the *value* methods return
 * `null` for a key that is absent. Those are different things and are kept different on purpose: an
 * application that treated a failed cache as a miss would silently re-fetch everything, and one that
 * treated a miss as a failure would break whenever the cache was cold.
 */
final class Kv
{
    /** @var Worker the worker this storage is reached through */
    private Worker $worker;

    /** @var string the storage's configured name, e.g. "sessions" */
    private string $storage;

    /**
     * How a value is turned into bytes and back.
     *
     * Defaults to `json_encode`/`json_decode` with `true` for associative arrays, which is what an
     * application expects when it caches an array. **Replace it** with `serialize`/`unserialize` to
     * store objects, or with an encrypting pair — both work because the server never looks.
     *
     * A callable pair rather than a class name, so that a closure capturing a key can be used without
     * a wrapper class existing. The decode side is given the bytes and must return the value; a
     * throw from it propagates as the caller's, since the server cannot be blamed for a value *this*
     * client wrote in a format *this* client cannot read.
     *
     * @var callable(mixed): string
     */
    private $encode;

    /** @var callable(string): mixed */
    private $decode;

    /**
     * @param callable(mixed): string|null $encode
     * @param callable(string): mixed|null $decode
     */
    public function __construct(Worker $worker, string $storage, ?callable $encode = null, ?callable $decode = null)
    {
        if ($storage === '') {
            throw new \InvalidArgumentException('a kv storage must be named: the server refuses a call that does not say which one');
        }

        $this->worker = $worker;
        $this->storage = $storage;
        $this->encode = $encode ?? static fn (mixed $value): string => json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $this->decode = $decode ?? static fn (string $bytes): mixed => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The storage's name, as configured.
     */
    public function storage(): string
    {
        return $this->storage;
    }

    /**
     * Replace how values are serialized.
     *
     * The pair must be inverse. A mismatch is not detectable here — the server cannot tell, which is
     * the point — and it shows up as a value that decodes to something else or throws.
     *
     * @param callable(mixed): string $encode
     * @param callable(string): mixed $decode
     */
    public function serializer(callable $encode, callable $decode): self
    {
        $this->encode = $encode;
        $this->decode = $decode;

        return $this;
    }

    /**
     * Fetch a value, or `null` if there is none.
     *
     * The second return value — reached through the `$found` reference — is what PSR-16 cannot express
     * and a cache with nullable values needs: `['name' => null]` is a stored value, and so is a key
     * nobody has ever written. Without it, an application caching a `null` would re-fetch it for ever.
     *
     * @param bool|null $found set to whether the key was present, whatever the value.
     */
    public function get(string $key, ?bool &$found = null): mixed
    {
        $answer = $this->ask($this->method('get'), ['key' => self::key($key)]);
        $value = $answer['value'] ?? null;
        $found = $value !== null;

        if (!$found) {
            return null;
        }

        return ($this->decode)(self::unbase64($value));
    }

    /**
     * Store a value.
     *
     * `$ttl` is in seconds. `null` means no expiry, and **`0` means delete**, which is PSR-16's rule and
     * the server enforces it: a caller passing zero expecting an immediate expiry would otherwise get a
     * key that never goes away.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            // PSR-16's rule — "a negative or zero TTL MUST cause the item to be deleted" — applied here
            // rather than sent, because the wire carries an unsigned count of seconds and a negative one
            // has no representation in it. A client that passed `-1` expecting a delete gets one; a
            // client that passed it expecting a very long expiry has made a mistake this cannot guess at,
            // and deleting is the reading that cannot lose data.
            return $this->delete($key);
        }

        $params = [
            'key' => self::key($key),
            'value' => self::base64(($this->encode)($value)),
        ];
        if ($ttl !== null) {
            $params['ttl'] = $ttl;
        }

        $answer = $this->ask($this->method('set'), $params);

        return ($answer['stored'] ?? false) === true;
    }

    /**
     * Store a value **only if the key is not already there**, answering whether it was stored.
     *
     * This is the primitive a lock cannot be. A TTL lock has no fencing token, so a worker that stalls
     * past its deadline can be inside a section somebody else has entered — the server says so at
     * [Worker::lock](../README.md) and in `internal/plugins.md` §9.2. Where a real invariant is at stake, the
     * storage decides instead: `true` means this call's value is the one under the key, `false` means
     * somebody else's already was, and **`false` is a success** — a caller that treats it as a failure
     * retries a write that was correctly refused.
     *
     * The atomicity is the driver's: `SET NX` on Redis, a shard lock in memory, `O_CREAT|O_EXCL` on the
     * file driver. A check-then-act on the client side would not be — that is why the server grew the
     * method rather than leaving it to be composed out of `has` and `set`.
     *
     * `$ttl` is in seconds and behaves as it does on [set], except that `0` is **refused by the server**
     * rather than treated as a delete: a conditional write that immediately deletes is a no-op nobody
     * asked for.
     */
    public function setIfAbsent(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            throw new \InvalidArgumentException(
                'setIfAbsent does not take a zero or negative TTL; it would delete the key it was '
                . 'asked to create. Pass null for no expiry, or use delete().'
            );
        }

        $params = [
            'key' => self::key($key),
            'value' => self::base64(($this->encode)($value)),
        ];
        if ($ttl !== null) {
            $params['ttl'] = $ttl;
        }

        $answer = $this->ask($this->method('setIfAbsent'), $params);

        return ($answer['stored'] ?? false) === true;
    }

    /**
     * Whether a key is stored, without reading it.
     */
    public function has(string $key): bool
    {
        $answer = $this->ask($this->method('has'), ['key' => self::key($key)]);

        return ($answer['present'] ?? false) === true;
    }

    /**
     * Remove a key, answering whether it was there.
     */
    public function delete(string $key): bool
    {
        $answer = $this->ask($this->method('delete'), ['key' => self::key($key)]);

        return ($answer['deleted'] ?? false) === true;
    }

    /**
     * How long a key has left.
     *
     * Returns `'missing'` or `'forever'` — two strings, not two numbers — or an `int` of seconds. A
     * three-state answer because two states have no remaining time and only one of them has a value
     * behind it; `getTtl` in PSR-16 returns `null` for both, which is a distinction an application
     * counting its own misses needs.
     */
    public function ttl(string $key): int|string
    {
        $answer = $this->ask($this->method('ttl'), ['key' => self::key($key)]);
        $ttl = $answer['ttl'] ?? 'missing';

        // A float is not returned by the server, but a JSON number that happens to be whole decodes as
        // one on some platforms, so it is normalised here rather than documented as a requirement.
        return is_string($ttl) ? $ttl : (int) $ttl;
    }

    /**
     * Remove every key in **this** storage.
     *
     * Not `flush`: a deployment with two storages must not have one call empty both. The server's own
     * note says the same thing.
     */
    public function clear(): bool
    {
        $answer = $this->ask($this->method('clear'));

        return ($answer['cleared'] ?? false) === true;
    }

    /**
     * Fetch several keys at once.
     *
     * The answer is keyed by the keys that were *found*, and `$missing` receives the ones that were not.
     * PSR-16's shape — an array keyed by key, absent keys simply missing — cannot express a stored
     * `null`, and this can, so the two are separated rather than merged.
     *
     * @param list<string> $keys
     * @param list<string>|null $missing set to the keys that had no value.
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, ?array &$missing = null): array
    {
        $answer = $this->ask($this->method('getMultiple'), [
            'keys' => array_map(self::key(...), $keys),
        ]);
        $values = $answer['values'] ?? [];
        $found = [];
        $missing = [];

        foreach (array_values($keys) as $index => $key) {
            $encoded = $values[$index] ?? null;
            if ($encoded === null) {
                $missing[] = $key;
                continue;
            }
            $found[$key] = ($this->decode)(self::unbase64($encoded));
        }

        return $found;
    }

    /**
     * Store several values at once, all with the same TTL.
     *
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $encoded = [];
        foreach ($values as $key => $value) {
            $encoded[self::key((string) $key)] = self::base64(($this->encode)($value));
        }

        $params = ['values' => $encoded];
        if ($ttl !== null) {
            $params['ttl'] = $ttl;
        }
        $answer = $this->ask($this->method('setMultiple'), $params);

        return ($answer['stored'] ?? 0) === count($encoded);
    }

    /**
     * Fetch a number of keys that were set together, or `null` for any that are absent.
     *
     * A convenience over {@see Kv::getMultiple} that keeps the *positions*, which is what a caller
     * iterating one list of keys wants.
     *
     * @param list<string> $keys
     * @return list<mixed>
     */
    public function getMany(array $keys): array
    {
        $answer = $this->ask($this->method('getMultiple'), [
            'keys' => array_map(self::key(...), $keys),
        ]);
        $values = $answer['values'] ?? [];

        $out = [];
        foreach (array_values($keys) as $index => $key) {
            $encoded = $values[$index] ?? null;
            $out[] = $encoded === null ? null : ($this->decode)(self::unbase64($encoded));
        }

        return $out;
    }

    /**
     * Make a call, and turn a refusal into an exception rather than a result.
     *
     * **The distinction this exists for is the one an application is least able to notice.** A refused
     * call — an ungranted storage, a malformed key, a store that is full — leaves `Worker::call` returning
     * `null` with the reason recorded in `lastCallError`. Read without this check, that `null` is
     * indistinguishable from "this key is not cached", so an application whose cache was never granted
     * would re-derive everything on every request and look perfectly healthy doing it. That is exactly the
     * failure a cache is deployed to prevent, arriving without a log line.
     *
     * A call that failed is therefore an exception here, and one the caller may catch. There is no
     * "return a default" mode: a caller that wants to treat a cache failure as a miss can catch this and
     * say so, which is a decision it has made rather than one it has inherited.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws ProtocolException if the server refused the call.
     */
    private function ask(string $name, array $params = []): array
    {
        $answer = $this->worker->call($name, $params);

        $error = $this->worker->lastCallError();
        if ($error !== null) {
            throw new ProtocolException(sprintf(
                'the server refused %s: %s%s',
                $name,
                $error,
                $this->worker->lastCallWasUnknown()
                    ? ' (and the call may have taken effect, so retrying it is not necessarily safe)'
                    : '',
            ));
        }

        return is_array($answer) ? $answer : [];
    }

    /**
     * The method name the server routes on: `kv.<storage>.<verb>`.
     *
     * The storage is part of the method rather than a parameter, because the server's namespace is `kv`
     * and the whole string after it selects the store — so a typo in the storage is a refusal naming the
     * storages this worker may reach, rather than a call that reaches the wrong one.
     */
    private function method(string $verb): string
    {
        return "kv.{$this->storage}.{$verb}";
    }

    /**
     * A key, base64-encoded.
     *
     * Encoding the key rather than sending it as text is the same decision as for values: a key is an
     * opaque string to the server, and an application that hashes or encrypts its keys would otherwise
     * have bytes above `0x7f` that JSON cannot carry.
     */
    private static function key(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('a kv key must not be empty; the server refuses one rather than storing it');
        }

        return self::base64($key);
    }

    private static function base64(string $bytes): string
    {
        return base64_encode($bytes);
    }

    private static function unbase64(mixed $encoded): string
    {
        if (!is_string($encoded)) {
            throw new ProtocolException('the server sent a value that was not a base64 string');
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new ProtocolException('the server sent a value that is not valid base64');
        }

        return $decoded;
    }
}
