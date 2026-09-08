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
     * Hard ceiling on the number of 256-bit entropy batches fetched while
     * rejection sampling before giving up.
     *
     * This is a safety net, not a tuning knob: a correct entropy source accepts
     * within the first batch with overwhelming probability (the per-chunk
     * rejection rate is always below 50%), so reaching this bound means the
     * source is degenerate. The value is deliberately large enough never to
     * false-trip on a healthy source while still guaranteeing integer()
     * terminates.
     */
    private const MAX_ENTROPY_BATCHES = 1000;

    public function __construct(private readonly QuantumDevice $device) {}

    /**
     * Generate raw entropy bytes.
     */
    public function generate(int $bits): string
    {
        if ($bits < 1) {
            throw new \InvalidArgumentException(
                "Requested bit count ({$bits}) must be a positive integer."
            );
        }

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
     *
     * Any bounds are accepted as long as $max - $min fits in a signed 64-bit
     * integer, so integer(0, PHP_INT_MAX) works while
     * integer(PHP_INT_MIN, PHP_INT_MAX) is rejected.
     */
    public function integer(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException(
                "Minimum value ({$min}) must not exceed maximum value ({$max})."
            );
        }

        $range = $max - $min;

        // PHP promotes an overflowing subtraction to float: such a span needs
        // more than 63 bits, which bindec() cannot return as an integer.
        if (! is_int($range)) {
            throw new \InvalidArgumentException(
                "The span between {$min} and {$max} exceeds PHP_INT_MAX; request a range that fits in a signed 64-bit integer."
            );
        }

        // Edge case: single possible value.
        if ($range === 0) {
            return $min;
        }

        // decbin() gives the exact bit length; ceil(log(range + 1, 2)) loses
        // precision above 2^53 and under-counts for ranges such as 2^62.
        $bitsNeeded = strlen(decbin($range));

        // 1 << 63 overflows to a negative float, so the widest mask is spelled out.
        $mask = $bitsNeeded >= 63 ? PHP_INT_MAX : (1 << $bitsNeeded) - 1;

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
