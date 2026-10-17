<?php

declare(strict_types=1);

/**
 * The PHP side of the protocol freeze.
 *
 * Every golden value here is the *same literal* that
 * `crates/volucer-worker/tests/wire_format.rs` in the `sailantis/volucer`
 * repository asserts. That is the point: if
 * either implementation drifts, one of the two tests fails, and neither can
 * quietly become "whatever the code currently does".
 *
 * The fixtures were originally computed independently of both implementations,
 * with a throwaway model of the header and CRC, so they are not a transcript of
 * either one.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\ProtocolException;

$hex = static fn (string $bytes): string => bin2hex($bytes);

// ---------------------------------------------------------------- CRC-32C

Assert::same(
    0xE3069283,
    Frame::crc32c('123456789'),
    'crc32c matches the standard check value (and Rust\'s crc32c)',
);
Assert::same(0x00000000, Frame::crc32c(''), 'crc32c of empty input is zero');
Assert::same(
    0x7B9F1283,
    Frame::crc32c('{"status":200,"headers":[],"body_len":0}'),
    'crc32c of the golden RESPONSE payload',
);
Assert::same(
    0xB3E50F77,
    Frame::crc32c('{"app_status":0,"peak_memory_bytes":0,"duration_us":0,"keep_alive":true}'),
    'crc32c of the golden END payload (0xB3E50F77, which the wire carries as 77 0f e5 b3)',
);
Assert::true(
    Frame::crc32c('123456789') !== 0xCBF43926,
    'crc32c is not crc32b (the polynomial zlib and plain crc32() use)',
);

// ---------------------------------------------------------- header layout

// A distinct value in every field, so this pins field order and endianness
// rather than merely round-tripping.
$header = pack('VCCvVV', 0x11223344, 1, MessageType::HELLO, 0x5566, 0x778899AA, 0xBBCCDDEE);
Assert::same(
    '4433221101016655aa998877eeddccbb',
    $hex($header),
    'the header lays its fields out little-endian at the documented offsets',
);

$decoded = Header::decode($header);
Assert::same(0x11223344, $decoded->payloadLen, 'decoded payload_len');
Assert::same(MessageType::HELLO, $decoded->messageType, 'decoded message_type');
Assert::same(0x5566, $decoded->flags, 'decoded flags');
Assert::same(0x778899AA, $decoded->requestId, 'decoded request_id');
Assert::same(0xBBCCDDEE, $decoded->checksum, 'decoded checksum');

// The canonical zero fixture: easiest for a second implementation to match.
Assert::same(
    '00000000010100000000000000000000',
    $hex((new Frame(MessageType::HELLO, 0))->encode()),
    'a HELLO with an empty payload is the canonical zero fixture',
);

// ------------------------------------------------------ the golden exchange

// RESPONSE(status 200, no headers, empty body) then END(keep_alive). Identical
// to the fixture in the Rust wire_format test, byte for byte.
$head = json_encode(['status' => 200, 'headers' => [], 'body_len' => 0], JSON_UNESCAPED_SLASHES);
Assert::same('{"status":200,"headers":[],"body_len":0}', $head, 'the RESPONSE JSON shape is frozen');

$end = json_encode(
    ['app_status' => 0, 'peak_memory_bytes' => 0, 'duration_us' => 0, 'keep_alive' => true],
    JSON_UNESCAPED_SLASHES,
);
Assert::same(
    '{"app_status":0,"peak_memory_bytes":0,"duration_us":0,"keep_alive":true}',
    $end,
    'the END JSON shape is frozen',
);

$exchange = (new Frame(MessageType::RESPONSE, 1, $head))->encode()
    . (new Frame(MessageType::END, 1, $end))->encode();

$expectedExchange = '28000000' . '01' . '04' . '0000' . '01000000' . '83129f7b'
    . '7b22737461747573223a3230302c2268656164657273223a5b5d2c22626f64795f6c656e223a307d'
    . '48000000' . '01' . '0b' . '0000' . '01000000' . '770fe5b3'
    . '7b226170705f737461747573223a302c227065616b5f6d656d6f72795f6279746573223a302c'
    . '226475726174696f6e5f7573223a302c226b6565705f616c697665223a747275657d';

Assert::same($expectedExchange, $hex($exchange), 'the golden exchange matches the Rust fixture');
Assert::same(144, strlen($exchange), 'the golden exchange is two frames of 16 + payload');

// ------------------------------------------------------------ message types

$frozen = [
    [MessageType::HELLO, 1], [MessageType::HELLO_ACK, 2], [MessageType::REQUEST, 3],
    [MessageType::RESPONSE, 4], [MessageType::BODY, 5], [MessageType::EVENT, 6],
    [MessageType::LOG, 7], [MessageType::METRIC, 8], [MessageType::CONTROL, 9],
    [MessageType::CONTROL_ACK, 10], [MessageType::END, 11], [MessageType::PING, 12],
    [MessageType::PONG, 13], [MessageType::ERROR, 14], [MessageType::CALL, 15],
    [MessageType::RETURN, 16], [MessageType::JOB, 17], [MessageType::ACK, 18],
];

foreach ($frozen as [$type, $number]) {
    $bytes = (new Frame($type, 0))->encode();
    Assert::same($number, ord($bytes[5]), MessageType::name($type) . ' is wire type ' . $number);
    Assert::same($number, $type, MessageType::name($type) . ' constant value is frozen');
}
Assert::same(18, count($frozen), 'v1 defines exactly 18 message types');

// ------------------------------------------------------------- last-flag bit

$lastBody = (new Frame(MessageType::BODY, 7, 'ok', Header::FLAG_LAST))->encode();
Assert::same('0400', $hex(substr($lastBody, 6, 2)), 'LAST sets only the last bit');
Assert::same('07000000', $hex(substr($lastBody, 8, 4)), 'the request id sits at offset 8');
$decodedLast = Header::decode(substr($lastBody, 0, 16));
Assert::true($decodedLast->isLast(), 'the LAST flag decodes');
Assert::same(0, $decodedLast->flags & Header::FLAG_COMPRESSED, 'COMPRESSED is not set');
Assert::same(0, $decodedLast->flags & Header::FLAG_CHUNKED, 'CHUNKED is not set');

// ------------------------------------------------- the no-checksum frame

// The bit's value is part of the protocol for the same reason the message
// numbers are: the Rust end tests it.
Assert::same(0x0010, Header::FLAG_NO_CHECKSUM, 'NO_CHECKSUM is bit four of the flags field');
Assert::same(
    0,
    Header::FLAG_NO_CHECKSUM & (Header::FLAG_COMPRESSED | Header::FLAG_CHUNKED
        | Header::FLAG_LAST | Header::FLAG_ERROR),
    'NO_CHECKSUM is disjoint from every other defined bit',
);

// Computed independently (a throwaway model of the header and the CRC-32C), so
// this is not merely "whatever the code currently does". The payload is
// `{"protocol_version":1,"no_checksum":true}`, the same fixture the Rust
// `a_hello_frame_matches_its_golden_bytes` asserts.
$unchecked = (new Frame(MessageType::HELLO, 0, json_encode([
    'protocol_version' => 1,
    'no_checksum' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))->encode(false);
Assert::same(
    '29000000' . '01' . '01' . '1000' . '00000000' . '00000000'
        . '7b2270726f746f636f6c5f76657273696f6e223a312c226e6f5f636865636b73756d223a747275657d',
    $hex($unchecked),
    'an unchecked frame raises the flag and zeroes the checksum field',
);
Assert::same(16 + 41, strlen($unchecked), 'and the header keeps its 16 bytes');
Assert::true(
    Header::decode(substr($unchecked, 0, 16))->declaresNoChecksum(),
    'the flag decodes back',
);

// This is exactly why the flag exists rather than a zero sentinel: an empty
// payload's real checksum *is* zero, so a value-based sentinel would be
// indistinguishable from a correct checksum — and would let corruption of the
// field itself turn the check off. The flags are what tell them apart.
$checkedEmpty = (new Frame(MessageType::END, 1))->encode();
$uncheckedEmpty = (new Frame(MessageType::END, 1))->encode(false);
Assert::same('010b0000', $hex(substr($checkedEmpty, 4, 4)), 'a checked empty frame: version 1, END, no flags');
Assert::same('1000', $hex(substr($uncheckedEmpty, 6, 2)), 'the same payload unchecked raises the flag');
Assert::same(
    $hex(substr($checkedEmpty, 12, 4)),
    $hex(substr($uncheckedEmpty, 12, 4)),
    'and the checksum field is zero in both, which is precisely the ambiguity the flag resolves',
);
Assert::same(
    false,
    Header::decode(substr($checkedEmpty, 0, 16))->declaresNoChecksum(),
    'the checked frame does not claim to be unchecked',
);

// ----------------------------------------------------------------- refusals

Assert::throws(
    static fn () => Header::decode(pack('VCCvVV', 0, 2, MessageType::HELLO, 0, 0, 0)),
    'a wrong protocol version is refused rather than guessed at',
    'protocol version',
);
Assert::throws(
    static fn () => Header::decode(pack('VCCvVV', 0, 1, 19, 0, 0, 0)),
    'type 19 is refused, because the table is 1..18',
    'unknown message type',
);
// 15–18 are CALL, RETURN, JOB and ACK, so they must *decode* rather than be refused — this is the run
// that would break if the whitelist were left at 14.
Assert::same(
    MessageType::CALL,
    Header::decode(pack('VCCvVV', 0, 1, 15, 0, 0, 0))->messageType,
    'type 15 decodes as CALL',
);
Assert::same(
    MessageType::RETURN,
    Header::decode(pack('VCCvVV', 0, 1, 16, 0, 0, 0))->messageType,
    'type 16 decodes as RETURN',
);
Assert::same(
    MessageType::JOB,
    Header::decode(pack('VCCvVV', 0, 1, 17, 0, 0, 0))->messageType,
    'type 17 decodes as JOB',
);
Assert::same(
    MessageType::ACK,
    Header::decode(pack('VCCvVV', 0, 1, 18, 0, 0, 0))->messageType,
    'type 18 decodes as ACK',
);
Assert::throws(
    static fn () => Header::decode(pack('VCCvVV', 0, 1, 0, 0, 0, 0)),
    'type 0 is refused',
    'unknown message type',
);
Assert::throws(
    static fn () => Header::decode(pack('VCCvVV', 0, 1, MessageType::REQUEST, Header::FLAG_COMPRESSED, 1, 0)),
    'the reserved COMPRESSED flag is refused rather than misread',
    'compressed frame',
);
Assert::throws(
    static fn () => Header::decode(substr($header, 0, 6)),
    'a header that is not exactly 16 bytes is refused',
    'exactly 16 bytes',
);

// A frame whose payload was altered must fail its checksum. This is the check
// that turns "output leaked into the stream" into a clear error.
$good = (new Frame(MessageType::REQUEST, 1, '{"method":"GET"}'))->encode();
$tampered = substr($good, 0, -1) . 'X';
$tamperedHeader = Header::decode(substr($tampered, 0, 16));
$tamperedPayload = substr($tampered, 16);
Assert::true(
    Frame::crc32c($tamperedPayload) !== $tamperedHeader->checksum,
    'a tampered payload fails the checksum the header carries',
);

exit(Assert::report('wire format'));
