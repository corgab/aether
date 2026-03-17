<?php

declare(strict_types=1);

namespace Aether\Entropy;

use Aether\Contracts\QuantumDevice;

/**
 * High-level entropy generator backed by a quantum device.
 */
class EntropyGenerator
{
    /**
     * @param  QuantumDevice  $device
     */
    public function __construct(private readonly QuantumDevice $device) {}

    /**
     * Generate raw entropy bytes.
     *
     * @param  int  $bits
     * @return string
     */
    public function generate(int $bits): string
    {
        return $this->device->generateEntropy($bits);
    }

    /**
     * Generate entropy as a lowercase hexadecimal string.
     *
     * @param  int  $bits
     * @return string
     */
    public function hex(int $bits): string
    {
        return bin2hex($this->generate($bits));
    }

    /**
     * Generate an unbiased random integer in [$min, $max] using rejection sampling.
     *
     * @param  int  $min
     * @param  int  $max
     * @return int
     */
    public function integer(int $min, int $max): int
    {
        $range = $max - $min;

        // Edge case: single possible value.
        if ($range === 0) {
            return $min;
        }

        $bitsNeeded = (int) ceil(log($range + 1, 2));
        $mask = (1 << $bitsNeeded) - 1;

        while (true) {
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
            // Buffer exhausted; fetch another batch (extremely unlikely).
        }
    }

    /**
     * Convert a raw byte string into a binary digit string.
     *
     * @param  string  $bytes
     * @return string
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
