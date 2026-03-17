<?php declare(strict_types=1);

use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a hex string that represents exactly $byteCount random-looking bytes.
 * Each byte is represented as 2 hex chars, so total hex length = $byteCount * 2.
 */
function makeHexBytes(int $byteCount): string
{
    return str_repeat('ab', $byteCount); // 'ab' per byte → deterministic fixture
}

// ─────────────────────────────────────────────────────────────────────────────
// Setup
// ─────────────────────────────────────────────────────────────────────────────

$device    = null;
$generator = null;

beforeEach(function () use (&$device, &$generator): void {
    $device    = $this->createMock(QuantumDevice::class);
    $generator = new EntropyGenerator($device);
});

// ─────────────────────────────────────────────────────────────────────────────
// generate()
// ─────────────────────────────────────────────────────────────────────────────

it('generate returns raw bytes of correct length for 8 bits', function () use (&$device, &$generator): void {
    // 8 bits → 1 byte → device should receive hex of 1 byte (2 hex chars)
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(8)
        ->willReturn('ff');

    $result = $generator->generate(8);

    expect($result)->toBeString();
    expect(strlen($result))->toBe(1); // 1 raw byte
    expect($result)->toBe("\xff");
});

it('generate returns raw bytes of correct length for 32 bits', function () use (&$device, &$generator): void {
    // 32 bits → 4 bytes → device returns 8-char hex
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(32)
        ->willReturn('deadbeef');

    $result = $generator->generate(32);

    expect(strlen($result))->toBe(4);
    expect($result)->toBe("\xde\xad\xbe\xef");
});

it('generate delegates bit count directly to device', function () use (&$device, &$generator): void {
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(128)
        ->willReturn(str_repeat('00', 16)); // 16 bytes of zeros

    $result = $generator->generate(128);

    expect(strlen($result))->toBe(16);
});

// ─────────────────────────────────────────────────────────────────────────────
// hex()
// ─────────────────────────────────────────────────────────────────────────────

it('hex returns lowercase hex string', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->willReturn('deadbeef');

    $result = $generator->hex(32);

    expect($result)->toBe('deadbeef');
    expect(ctype_xdigit($result))->toBeTrue();
});

it('hex length is double the byte count', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->with(64)
        ->willReturn(str_repeat('a1', 8)); // 8 bytes

    $result = $generator->hex(64);

    expect(strlen($result))->toBe(16); // 8 bytes × 2 hex chars
});

// ─────────────────────────────────────────────────────────────────────────────
// integer()
// ─────────────────────────────────────────────────────────────────────────────

it('integer returns value within [min, max] range', function () use (&$device, &$generator): void {
    // Range 0–9 → needs 4 bits (ceil(log(10,2))=ceil(3.32)=4).
    // Bitmask = 0b1111 = 15.
    // Provide 256 bits = 32 bytes.
    // Use all-zeros bytes so first chunk is 0000 → bindec('0000') = 0 ≤ 9 → return min+0 = 5.
    $device
        ->method('generateEntropy')
        ->with(256)
        ->willReturn(str_repeat('00', 32));

    $result = $generator->integer(5, 14);

    expect($result)->toBeGreaterThanOrEqual(5);
    expect($result)->toBeLessThanOrEqual(14);
});

it('integer returns exact min when all bits are zero', function () use (&$device, &$generator): void {
    // min=10, max=20, range=10 → 4 bits needed.
    // All-zero bytes → first chunk = 0 ≤ 10 → returns min+0 = 10.
    $device
        ->method('generateEntropy')
        ->with(256)
        ->willReturn(str_repeat('00', 32));

    $result = $generator->integer(10, 20);

    expect($result)->toBe(10);
});

it('integer with equal min and max always returns that value', function () use (&$device, &$generator): void {
    // range = 0, bitsNeeded = 0, but we handle this edge: log(1, 2) = 0 → ceil = 0.
    // Value from 0-bit chunk = 0 <= 0, so return min+0 = 42.
    $device
        ->method('generateEntropy')
        ->willReturn(str_repeat('00', 32));

    $result = $generator->integer(42, 42);

    expect($result)->toBe(42);
});

it('integer uses rejection sampling to discard out-of-range values', function () use (&$device, &$generator): void {
    // Range 0–1 (needs 1 bit). With 1 bit, values 0 and 1 are both valid (≤ 1).
    // Use range 0–2 (needs 2 bits, values 0-3, discard 3=0b11).
    // min=0, max=2, range=2 → bits = ceil(log(3,2)) = ceil(1.58) = 2, mask = 0b11 = 3.
    // Byte 0xFF = 0b11111111 → chunks: 11,11,11,11 → all 3 → all rejected.
    // Byte 0x00 = 0b00000000 → chunks: 00,00,00,00 → all 0 → first accepted = min+0 = 0.
    // Give device two consecutive calls: first all-0xFF (32 bytes), then all-0x00 (32 bytes).
    $device
        ->expects($this->exactly(2))
        ->method('generateEntropy')
        ->with(256)
        ->willReturnOnConsecutiveCalls(
            str_repeat('ff', 32), // all chunks = 3, rejected
            str_repeat('00', 32), // all chunks = 0, accepted
        );

    $result = $generator->integer(0, 2);

    expect($result)->toBe(0);
});

it('integer fetches exactly one 256-bit batch when first chunk is valid', function () use (&$device, &$generator): void {
    // min=0, max=255 → range=255, bits=8, mask=0xFF.
    // First byte of 0x03 batch → chunk = 0x03 = 3 ≤ 255, immediately accepted.
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(256)
        ->willReturn('03' . str_repeat('00', 31));

    $result = $generator->integer(0, 255);

    expect($result)->toBe(3);
});

it('integer fetches another batch when buffer is exhausted', function () use (&$device, &$generator): void {
    // min=0, max=2, range=2 → 2 bits per chunk, 256 bits = 128 chunks.
    // All-0xFF → all 128 chunks = 3, all rejected → buffer exhausted → second call.
    // Second call all-zeros → first chunk = 0 → accepted, return 0.
    $device
        ->expects($this->exactly(2))
        ->method('generateEntropy')
        ->with(256)
        ->willReturnOnConsecutiveCalls(
            str_repeat('ff', 32),
            str_repeat('00', 32),
        );

    $result = $generator->integer(0, 2);

    expect($result)->toBe(0);
});
