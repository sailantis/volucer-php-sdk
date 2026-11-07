<?php

declare(strict_types=1);

namespace Volucer;

/**
 * A gRPC call result. The status and failure reason are sent in trailers; the payload contains
 * handler-provided, already-framed message bytes. Failed calls have no payload.
 */
final class GrpcResponse
{
    // Standard gRPC status codes; other gRPC status values may also be passed.
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
     * @param int $status A standard or otherwise valid gRPC status code.
     * @param string $payload Framed response bytes, or empty.
     * @param string $reason Human-readable failure reason for the `grpc-message` trailer.
     * @param string $contentType Response media type.
     */
    public function __construct(
        public int $status = self::OK,
        public string $payload = '',
        public string $reason = '',
        public string $contentType = 'application/grpc+proto',
    ) {
    }

    /**
     * Create a successful response with already-framed payload bytes.
     */
    public static function message(string $payload, string $contentType = 'application/grpc+proto'): self
    {
        return new self(self::OK, $payload, '', $contentType);
    }

    /**
     * Create a successful response with no payload.
     */
    public static function ok(string $contentType = 'application/grpc+proto'): self
    {
        return new self(self::OK, '', '', $contentType);
    }

    /**
     * Create a failed response with a human-readable `grpc-message` reason and no payload.
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
     * Return the status name for diagnostics, or an empty string for an unrecognized status.
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
