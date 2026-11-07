<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A response body produced incrementally, without a declared length.
 *
 * The server forwards chunks when supported; otherwise it may collect the body.
 * The SDK marks the final chunk, and a generator failure after the response head
 * is sent is reported as a truncated response.
 */
final class StreamedResponse
{
    /**
     * @param iterable<string>|\Closure():iterable<string> $chunks
     * @param list<array{0: string, 1: string}> $headers
     */
    public function __construct(
        private readonly iterable|\Closure $chunks,
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    /**
     * Return the chunks, invoking a factory closure if provided.
     *
     * The factory runs before the response head is sent; generator errors occur during streaming.
     *
     * @return iterable<string>
     */
    public function stream(): iterable
    {
        $chunks = $this->chunks;

        return $chunks instanceof \Closure ? $chunks() : $chunks;
    }

    /**
     * Create a streamed plain-text response.
     *
     * @param iterable<string>|\Closure():iterable<string> $chunks
     */
    public static function text(iterable|\Closure $chunks, int $status = 200): self
    {
        return new self($chunks, $status, [['Content-Type', 'text/plain; charset=utf-8']]);
    }

    /**
     * Create a streamed event-stream response with buffering disabled.
     *
     * @param iterable<string>|\Closure():iterable<string> $chunks
     */
    public static function events(iterable|\Closure $chunks, int $status = 200): self
    {
        return new self($chunks, $status, [
            ['Content-Type', 'text/event-stream'],
            ['Cache-Control', 'no-store'],
            ['X-Accel-Buffering', 'no'],
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[] = [$name, $value];
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }
}
