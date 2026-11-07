<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Outbound protocol frame containing a message type, request id, and payload.
 */
final class Frame
{
    public function __construct(
        public readonly int $messageType,
        public readonly int $requestId,
        public readonly string $payload = '',
        public readonly int $flags = 0,
    ) {
    }

    /**
     * Encode the 16-byte little-endian header followed by the payload.
     *
     * The worker supplies the checksum policy negotiated at handshake. When disabled, the checksum
     * is zeroed and {@see Header::FLAG_NO_CHECKSUM} marks it as omitted.
     */
    public function encode(bool $checksum = true): string
    {
        return pack(
            'VCCvVV',
            strlen($this->payload),
            Header::PROTOCOL_VERSION,
            $this->messageType,
            $checksum ? $this->flags : ($this->flags | Header::FLAG_NO_CHECKSUM),
            $this->requestId,
            $checksum ? self::crc32c($this->payload) : 0,
        ) . $this->payload;
    }

    /**
     * Return the CRC-32C value as a little-endian 32-bit integer.
     *
     * Reversing the raw digest avoids float conversion on 32-bit PHP. The standard check value is
     * `crc32c("123456789") === 0xE3069283`.
     */
    public static function crc32c(string $data): int
    {
        /** @var int $value */
        $value = unpack('V', strrev(hash('crc32c', $data, true)))[1];

        return $value;
    }
}
