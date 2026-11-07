<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Decoded 16-byte frame header. Wire fields are little-endian; golden fixtures in
 * tests/WireFormatTest.php verify compatibility with the server.
 *
 * Layout:
 *
 *   offset size field
 *   0      4    payload_len
 *   4      1    protocol_version
 *   5      1    message_type
 *   6      2    flags
 *   8      4    request_id
 *   12     4    crc32c(payload)
 */
final class Header
{
    public const PROTOCOL_VERSION = 1;
    public const HEADER_LEN = 16;

    /** Reserved. No codec is negotiated in v1, so a frame carrying it is refused. */
    public const FLAG_COMPRESSED = 0x0001;
    /** The payload is one chunk of a larger logical message. */
    public const FLAG_CHUNKED = 0x0002;
    /** This is the last chunk of a logical message. */
    public const FLAG_LAST = 0x0004;
    /** The sender is reporting a failure. */
    public const FLAG_ERROR = 0x0008;
    /**
     * The checksum is omitted. This requires handshake agreement; the field remains present and
     * zeroed. A flag is needed because zero is a valid CRC32C value (including for empty payloads).
     */
    public const FLAG_NO_CHECKSUM = 0x0010;
    /**
     * The response body length is unknown; see {@see StreamedResponse}. A flag distinguishes this
     * from a valid empty body.
     */
    public const FLAG_STREAMED = 0x0020;

    public function __construct(
        public readonly int $payloadLen,
        public readonly int $messageType,
        public readonly int $flags,
        public readonly int $requestId,
        public readonly int $checksum,
    ) {
    }

    /**
     * Parse an exactly 16-byte header. Short or oversized input is a truncated or malformed frame.
     */
    public static function decode(string $bytes): self
    {
        $length = strlen($bytes);
        if ($length !== self::HEADER_LEN) {
            throw new ProtocolException(sprintf(
                'a frame header is exactly %d bytes, got %d',
                self::HEADER_LEN,
                $length,
            ));
        }

        /** @var array{payload_len: int, version: int, type: int, flags: int, request_id: int, checksum: int} $fields */
        $fields = unpack('Vpayload_len/Cversion/Ctype/vflags/Vrequest_id/Vchecksum', $bytes);

        if ($fields['version'] !== self::PROTOCOL_VERSION) {
            throw new ProtocolException(sprintf(
                'unsupported protocol version %d (this SDK speaks %d)',
                $fields['version'],
                self::PROTOCOL_VERSION,
            ));
        }

        if ($fields['type'] < MessageType::HELLO || $fields['type'] > MessageType::ACK) {
            throw new ProtocolException(
                'unknown message type ' . $fields['type'] . ' (this SDK defines 1..18)'
            );
        }

        // No compression codec is negotiated, so compressed data cannot be decoded safely.
        if (($fields['flags'] & self::FLAG_COMPRESSED) !== 0) {
            throw new ProtocolException('refusing a compressed frame: no codec is negotiated');
        }

        return new self(
            $fields['payload_len'],
            $fields['type'],
            $fields['flags'],
            $fields['request_id'],
            $fields['checksum'],
        );
    }

    public function isLast(): bool
    {
        return ($this->flags & self::FLAG_LAST) !== 0;
    }

    /**
     * Whether the frame declares its checksum omitted. The reader must verify handshake agreement.
     */
    public function declaresNoChecksum(): bool
    {
        return ($this->flags & self::FLAG_NO_CHECKSUM) !== 0;
    }

    /**
     * The wire name of this frame's type, for diagnostics.
     */
    public function typeName(): string
    {
        return MessageType::name($this->messageType);
    }
}
