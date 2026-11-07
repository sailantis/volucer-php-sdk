<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Client for a configured key-value storage.
 *
 * Provides PSR-16-style operations, but is not a PSR-16 implementation. Values
 * are serialized client-side, and presence can be distinguished from a stored
 * null. Call failures raise {@see ProtocolException}; cache misses do not.
 */
final class Kv
{
    /** @var CallPlane whatever answers a call: a worker's channel, or the `[rpc]` listener */
    private CallPlane $calls;

    /** @var string the storage's configured name, e.g. "sessions" */
    private string $storage;

    /**
     * Value encoder. Defaults to JSON; custom encoders can serialize or encrypt values.
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
    public function __construct(CallPlane $calls, string $storage, ?callable $encode = null, ?callable $decode = null)
    {
        if ($storage === '') {
            throw new \InvalidArgumentException('a kv storage must be named: the server refuses a call that does not say which one');
        }

        $this->calls = $calls;
        $this->storage = $storage;
        $this->encode = $encode ?? static fn (mixed $value): string => json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $this->decode = $decode ?? static fn (string $bytes): mixed => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Return the configured storage name. */
    public function storage(): string
    {
        return $this->storage;
    }

    /**
     * Replace the value encoder and decoder; the pair must be compatible.
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
     * Fetch a value, setting `$found` to distinguish a miss from stored null.
     *
     * @param bool|null $found whether the key was present
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

    /** Store a value; TTL is in seconds, null means no expiry, and non-positive values delete. */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            // PSR-16 treats non-positive TTLs as deletion; the wire only accepts unsigned TTLs.
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
     * Atomically store a value only if the key is absent.
     *
     * False means the key already exists. TTL must be positive or null.
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

    /** Whether a key exists, without fetching its value. */
    public function has(string $key): bool
    {
        $answer = $this->ask($this->method('has'), ['key' => self::key($key)]);

        return ($answer['present'] ?? false) === true;
    }

    /** Remove a key and report whether it existed. */
    public function delete(string $key): bool
    {
        $answer = $this->ask($this->method('delete'), ['key' => self::key($key)]);

        return ($answer['deleted'] ?? false) === true;
    }

    /** Return remaining seconds, or `missing`/`forever`. */
    public function ttl(string $key): int|string
    {
        $answer = $this->ask($this->method('ttl'), ['key' => self::key($key)]);
        $ttl = $answer['ttl'] ?? 'missing';

        // Normalize JSON numeric values to an integer.
        return is_string($ttl) ? $ttl : (int) $ttl;
    }

    /** Remove every key in this storage. */
    public function clear(): bool
    {
        $answer = $this->ask($this->method('clear'));

        return ($answer['cleared'] ?? false) === true;
    }

    /**
     * Fetch multiple keys, returning found values and listing misses separately.
     *
     * @param list<string> $keys
     * @param list<string>|null $missing keys with no value
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
     * Store multiple values with the same TTL.
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
     * Fetch multiple values in input order; missing keys are null.
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
     * Make a call, raising on failure so it cannot be mistaken for a cache miss.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ProtocolException if the call failed.
     */
    private function ask(string $name, array $params = []): array
    {
        $answer = $this->calls->call($name, $params);

        $error = $this->calls->lastCallError();
        if ($error !== null) {
            // A call can fail before reaching the server, so do not imply it was refused there.
            throw new ProtocolException(sprintf(
                '%s failed: %s%s',
                $name,
                $error,
                $this->calls->lastCallWasUnknown()
                    ? ' (and the call may have taken effect, so retrying it is not necessarily safe)'
                    : '',
            ));
        }

        return is_array($answer) ? $answer : [];
    }

    /** Build the routed method name, `kv.<storage>.<verb>`. */
    private function method(string $verb): string
    {
        return "kv.{$this->storage}.{$verb}";
    }

    /** Validate and base64-encode an opaque key. */
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
