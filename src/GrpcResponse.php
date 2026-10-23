<?php

declare(strict_types=1);

namespace Volucer;

/**
 * The outcome of one gRPC call: a status, a message, and how the bytes are encoded.
 *
 * A gRPC reply is not an HTTP response, and modelling it as one is the mistake this class exists to
 * prevent. Its **outcome travels in the trailer section** — after the body, not in the status line —
 * because a streaming reply has to be able to send headers before its outcome exists. So a handler
 * says what happened by naming a status here, and the server moves it to where a client reads it.
 *
 * ## What `message` is, and what it is not
 *
 * `message` is the **body**: the raw, length-delimited framed bytes the client receives. The server
 * never parses or re-frames it, so a handler owns the framing entirely — write the compression flag
 * and the big-endian length yourself, or let whatever protobuf library you already use write it. This
 * SDK does not ship an encoder, because doing so would put a schema in the transport, which is the
 * whole of the server's position.
 *
 * The exception is [`GrpcResponse::fail`], where the message is a *sentence for a developer* rather
 * than a message for the wire: a failed call has no payload, and gRPC's own convention is that
 * `grpc-message` on a non-OK status carries a human-readable reason. It is percent-encoded by the
 * server, which is why it may contain anything.
 */
final class GrpcResponse
{
    // The statuses a *worker* has any business sending. The full range is gRPC's; these are the ones
    // an application picks between, named so that a caller writes `GrpcResponse::NOT_FOUND` rather
    // than a number whose meaning has to be looked up. A status outside this list may still be passed
    // as its number — gRPC's registry is the authority, not this class.
    public const OK = 0;
    public const CANCELLED = 1;
    public const UNKNOWN = 2;
    public const INVALID_ARGUMENT = 3;
    public const DEADLINE_EXCEEDED = 4;
    public const NOT_FOUND = 5;
    public const ALREADY_EXISTS = 6;
    public const PERMISSION_DENIED = 7;
    public const RESOURCE_EXHAUSTED = 8;
    public const FAILED_PRECONDITION = 9;
    public const ABORTED = 10;
    public const OUT_OF_RANGE = 11;
    public const UNIMPLEMENTED = 12;
    public const INTERNAL = 13;
    public const UNAVAILABLE = 14;
    public const DATA_LOSS = 15;
    public const UNAUTHENTICATED = 16;

    /**
     * @param int $status one of the constants above, or any status gRPC defines
     * @param string $payload the body: the framed message bytes, or empty
     * @param string $reason the `grpc-message` trailer: a sentence for a developer, or empty
     * @param string $contentType the media type to reply with, e.g. `application/grpc+proto`
     *
     * @see GrpcResponse::message for the success case, GrpcResponse::fail for the failure one
     */
    public function __construct(
        public int $status = self::OK,
        public string $payload = '',
        public string $reason = '',
        public string $contentType = 'application/grpc+proto',
    ) {
    }

    /**
     * A successful call carrying `$payload`.
     *
     * The name says what the argument is: the framed bytes the *client* will decode — a compression
     * flag, a big-endian length, then the message. Callers usually reach for this through a helper of
     * their own that encodes a protobuf message; the transport does not ship one, because shipping one
     * would put a schema in the transport.
     */
    public static function message(string $payload, string $contentType = 'application/grpc+proto'): self
    {
        return new self(self::OK, $payload, '', $contentType);
    }

    /**
     * A successful call with no payload.
     *
     * Distinct from a failure and from an empty message: gRPC has no way to send "a message that
     * happens to be empty" that a client can tell from "no message", so the answer is one constructor
     * rather than two that differ in a way nobody can observe.
     */
    public static function ok(string $contentType = 'application/grpc+proto'): self
    {
        return new self(self::OK, '', '', $contentType);
    }

    /**
     * A failed call, with `$reason` as the sentence a developer will read.
     *
     * `$reason` is **not** payload bytes, and there are none: a failed call carries no message, which
     * is why this constructor takes no payload at all rather than defaulting one. The server
     * percent-encodes the sentence into the `grpc-message` trailer, so it may contain spaces, quotes
     * and newlines.
     */
    public static function fail(int $status, string $reason = ''): self
    {
        return new self($status, '', $reason);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * The status name, for a log line rather than for the wire.
     *
     * The wire carries the number; a person reading a log reads this. An unrecognised status has no
     * name, which is the honest answer for one this class does not know about.
     */
    public function statusName(): string
    {
        return match ($this->status) {
            self::OK => 'OK',
            self::CANCELLED => 'CANCELLED',
            self::UNKNOWN => 'UNKNOWN',
            self::INVALID_ARGUMENT => 'INVALID_ARGUMENT',
            self::DEADLINE_EXCEEDED => 'DEADLINE_EXCEEDED',
            self::NOT_FOUND => 'NOT_FOUND',
            self::ALREADY_EXISTS => 'ALREADY_EXISTS',
            self::PERMISSION_DENIED => 'PERMISSION_DENIED',
            self::RESOURCE_EXHAUSTED => 'RESOURCE_EXHAUSTED',
            self::FAILED_PRECONDITION => 'FAILED_PRECONDITION',
            self::ABORTED => 'ABORTED',
            self::OUT_OF_RANGE => 'OUT_OF_RANGE',
            self::UNIMPLEMENTED => 'UNIMPLEMENTED',
            self::INTERNAL => 'INTERNAL',
            self::UNAVAILABLE => 'UNAVAILABLE',
            self::DATA_LOSS => 'DATA_LOSS',
            self::UNAUTHENTICATED => 'UNAUTHENTICATED',
            default => '',
        };
    }
}
