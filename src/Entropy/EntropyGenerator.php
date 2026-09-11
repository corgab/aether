<?php

declare(strict_types=1);

namespace Aether\Entropy;

use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\QuantumExecutionException;

/**
 * High-level entropy generator backed by a quantum device.
 *
 * The quality of the output is the device's: a QPU measures genuinely
 * random bits, a simulator (local or managed) draws them from a classical
 * pseudorandom number generator. Only hardware-backed entropy is suitable
 * for security-sensitive material such as keys, tokens and nonces.
 */
class EntropyGenerator
{
    /**
     * Hard ceiling on the number of 256-bit entropy batches fetched while
     * rejection sampling before giving up.
     */
    private const MAX_ENTROPY_BATCHES = 1000;

    public function __construct(private readonly QuantumDevice $device) {}

    /**
     * Generate raw entropy bytes.
     *
     * Returns ceil($bits / 8) bytes. A bit count that is not a multiple of 8
     * is rounded up before it reaches the device, so every returned byte is
     * fully measured rather than zero-padded.
     */
    public function generate(int $bits): string
    {
        if ($bits < 1) {
            throw QuantumExecutionException::invalidEntropyBitCount($bits);
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
            throw QuantumExecutionException::invalidEntropyRange($min, $max);
        }

        // A span wider than PHP_INT_MAX would overflow the subtraction and
        // need a 64-bit chunk, which bindec() can only return as a float.
        // With a non-negative $min the span cannot overflow; otherwise
        // PHP_INT_MAX + $min is the largest $max that still fits.
        if ($min < 0 && $max > PHP_INT_MAX + $min) {
            throw new \InvalidArgumentException(
                "The span between {$min} and {$max} exceeds PHP_INT_MAX; request a range that fits in the system's maximum integer size (PHP_INT_MAX)."
            );
        }

        $range = $max - $min;

        // Edge case: single possible value.
        if ($range === 0) {
            return $min;
        }

        // decbin() gives the exact bit length; ceil(log(range + 1, 2)) loses
        // precision above 2^53 and under-counts for ranges such as 2^62.
        $bitsNeeded = strlen(decbin($range));

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

                // The chunk is exactly $bitsNeeded digits, so no mask is needed.
                $value = (int) bindec($chunk);

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
        static $lookup = null;

        if ($lookup === null) {
            $lookup = [];
            for ($i = 0; $i < 256; $i++) {
                $lookup[chr($i)] = sprintf('%08b', $i);
            }
        }

        return strtr($bytes, $lookup);
    }
}
