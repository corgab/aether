<?php

declare(strict_types=1);

namespace Aether\Entropy;

use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\QuantumExecutionException;

/**
 * High-level entropy generator backed by a quantum device.
 */
class EntropyGenerator
{
    /**
     * Maximum number of 256-bit entropy batches to fetch while rejection
     * sampling before giving up. The normal path succeeds on the first batch.
     */
    private const MAX_ENTROPY_BATCHES = 1000;

    public function __construct(private readonly QuantumDevice $device) {}

    /**
     * Generate raw entropy bytes.
     */
    public function generate(int $bits): string
    {
        return $this->device->generateEntropy($bits);
    }

    /**
     * Generate entropy as a lowercase hexadecimal string.
     */
    public function hex(int $bits): string
    {
        return bin2hex($this->generate($bits));
    }

    /**
     * Generate an unbiased random integer in [$min, $max] using rejection sampling.
     */
    public function integer(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException(
                "Minimum value ({$min}) must not exceed maximum value ({$max})."
            );
        }

        $range = $max - $min;

        // Edge case: single possible value.
        if ($range === 0) {
            return $min;
        }

        $bitsNeeded = (int) ceil(log($range + 1, 2));
        $mask = (1 << $bitsNeeded) - 1;

        // A correct entropy source accepts on the first batch with overwhelming
        // probability; the cap is a safety net against a degenerate source that
        // would otherwise spin forever.
        for ($batch = 0; $batch < self::MAX_ENTROPY_BATCHES; $batch++) {
            $bitstring = $this->bytesToBitstring($this->generate(256));
            $length = strlen($bitstring);
            $offset = 0;

            while ($offset + $bitsNeeded <= $length) {
                $chunk = substr($bitstring, $offset, $bitsNeeded);
                $offset += $bitsNeeded;

                $value = (int) bindec($chunk) & $mask;

                if ($value <= $range) {
                    return $min + $value;
                }
                // Rejected — try the next chunk.
            }
            // Buffer exhausted; fetch another batch.
        }

        throw QuantumExecutionException::entropyExhausted($min, $max, self::MAX_ENTROPY_BATCHES);
    }

    /**
     * Convert a raw byte string into a binary digit string.
     */
    private function bytesToBitstring(string $bytes): string
    {
        $bits = '';

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
