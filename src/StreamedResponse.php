<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A response whose body is produced chunk by chunk, and whose length is never declared.
 *
 * # Why this exists
 *
 * A [`Response`] puts its body in the `RESPONSE` head — `body_len` — so the server has to hold the
 * whole thing before the status line can go out. That is the right shape for a page and the wrong one
 * for a CSV export, an SSE feed, or anything generated in a loop: it makes the peak memory of the
 * response equal to the size of the response.
 *
 * A streamed response declares no length and sets `FLAG_STREAMED` on its head instead, so the server
 * can pick a status, a `Content-Type` and a framing **before** the first byte and forward each chunk
 * as it arrives. The body then ends at the `BODY` frame carrying `FLAG_LAST`, which is the only end
 * signal left — so it is mandatory, and the SDK sets it for you on the last chunk.
 *
 * # What the server does with it
 *
 * Nothing is buffered on the server side, but **the framing depends on what is in front of it.** Over
 * FastCGI the response is relayed as records, so `nginx` streams it. Over HTTP the response is
 * chunked, so no `Content-Length` reaches the client — which is correct and is also why a deployment
 * that compresses responses cannot compress a streamed one.
 *
 * A response of a kind the server will not relay — a non-2xx status, a `X-Sendfile` instruction, or
 * anything when `stream_threshold_bytes = 0` — is collected and sent whole, exactly as before. The
 * SDK does not have to know which happened: the chunks are written either way.
 *
 * # Ordering
 *
 * The head is sent when the first chunk is pulled, not when this object is constructed, so an
 * application that decides its status inside the generator still gets that status. A generator that
 * yields nothing at all produces an empty body and a `LAST` on an empty frame, which the server
 * accepts as a complete zero-length response.
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
     * The chunks, as something `foreach` can walk.
     *
     * A `Closure` is invoked here rather than in the constructor, and **this is called before the
     * head goes out** — see {@see Worker::respondStreamed}. That ordering is what makes the
     * difference between the two failure modes: creating a generator does not run it, so a bad
     * factory is still answerable with a `500`, while a throw *inside* the generator happens after
     * the status is already on the wire and can only be reported as a truncated body.
     *
     * @return iterable<string>
     */
    public function stream(): iterable
    {
        $chunks = $this->chunks;

        return $chunks instanceof \Closure ? $chunks() : $chunks;
    }

    /**
     * A streamed `text/plain` response, which is what an export usually wants.
     *
     * `Content-Type` only: a streamed response cannot have a `Content-Length`, and adding one would
     * make the server's relay policy refuse to relay it — the two are inconsistent by definition.
     *
     * @param iterable<string>|\Closure():iterable<string> $chunks
     */
    public static function text(iterable|\Closure $chunks, int $status = 200): self
    {
        return new self($chunks, $status, [['Content-Type', 'text/plain; charset=utf-8']]);
    }

    /**
     * A streamed `text/event-stream` response, which is what SSE needs.
     *
     * `Cache-Control: no-store` and `X-Accel-Buffering: no` are set because an intermediary that
     * buffers will hold every event until the stream ends, which for SSE means the feature does not
     * work at all. `nginx` honours the second header; the first is for everything else.
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
