<?php declare(strict_types=1);

use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;

$device    = null;
$generator = null;

beforeEach(function () use (&$device, &$generator): void {
    $device    = $this->createMock(QuantumDevice::class);
    $generator = new EntropyGenerator($device);
});

it('generate returns raw bytes of correct length for 8 bits', function () use (&$device, &$generator): void {
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(8)
        ->willReturn("\xff");

    $result = $generator->generate(8);

    expect($result)->toBeString();
    expect(strlen($result))->toBe(1);
    expect($result)->toBe("\xff");
});

it('generate returns raw bytes of correct length for 32 bits', function () use (&$device, &$generator): void {
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(32)
        ->willReturn("\xde\xad\xbe\xef");

    $result = $generator->generate(32);

    expect(strlen($result))->toBe(4);
    expect($result)->toBe("\xde\xad\xbe\xef");
});

it('generate delegates bit count directly to device', function () use (&$device, &$generator): void {
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(128)
        ->willReturn(str_repeat("\x00", 16));

    $result = $generator->generate(128);

    expect(strlen($result))->toBe(16);
});

it('hex returns lowercase hex string', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->willReturn("\xde\xad\xbe\xef");

    $result = $generator->hex(32);

    expect($result)->toBe('deadbeef');
    expect(ctype_xdigit($result))->toBeTrue();
});

it('hex length is double the byte count', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->with(64)
        ->willReturn(str_repeat("\xa1", 8));

    $result = $generator->hex(64);

    expect(strlen($result))->toBe(16);
});

it('integer returns value within [min, max] range', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->with(256)
        ->willReturn(str_repeat("\x00", 32));

    $result = $generator->integer(5, 14);

    expect($result)->toBeGreaterThanOrEqual(5);
    expect($result)->toBeLessThanOrEqual(14);
});

it('integer returns exact min when all bits are zero', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->with(256)
        ->willReturn(str_repeat("\x00", 32));

    $result = $generator->integer(10, 20);

    expect($result)->toBe(10);
});

it('integer with equal min and max always returns that value', function () use (&$device, &$generator): void {
    $device
        ->method('generateEntropy')
        ->willReturn(str_repeat("\x00", 32));

    $result = $generator->integer(42, 42);

    expect($result)->toBe(42);
});

it('integer uses rejection sampling to discard out-of-range values', function () use (&$device, &$generator): void {
    $device
        ->expects($this->exactly(2))
        ->method('generateEntropy')
        ->with(256)
        ->willReturnOnConsecutiveCalls(
            str_repeat("\xff", 32),
            str_repeat("\x00", 32),
        );

    $result = $generator->integer(0, 2);

    expect($result)->toBe(0);
});

it('integer fetches exactly one 256-bit batch when first chunk is valid', function () use (&$device, &$generator): void {
    $device
        ->expects($this->once())
        ->method('generateEntropy')
        ->with(256)
        ->willReturn("\x03" . str_repeat("\x00", 31));

    $result = $generator->integer(0, 255);

    expect($result)->toBe(3);
});

it('integer fetches another batch when buffer is exhausted', function () use (&$device, &$generator): void {
    $device
        ->expects($this->exactly(2))
        ->method('generateEntropy')
        ->with(256)
        ->willReturnOnConsecutiveCalls(
            str_repeat("\xff", 32),
            str_repeat("\x00", 32),
        );

    $result = $generator->integer(0, 2);

    expect($result)->toBe(0);
});

// -------------------------------------------------------------------------
// Validation: min > max
// -------------------------------------------------------------------------

it('throws when min exceeds max', function () {
    $device = $this->createMock(\Aether\Contracts\QuantumDevice::class);
    $generator = new \Aether\Entropy\EntropyGenerator($device);
    $generator->integer(10, 5);
})->throws(\InvalidArgumentException::class, 'must not exceed');
