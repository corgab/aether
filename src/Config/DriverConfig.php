<?php

declare(strict_types=1);

namespace Aether\Config;

use Aether\Exceptions\InvalidDriverConfigException;

/**
 * Typed view over one driver's entry in `aether.drivers`.
 *
 * Built once by the driver constructor from the raw config array, so every
 * option the PHP layer reads is validated and cast in exactly one place. A
 * value that is neither blank nor of the documented shape throws
 * InvalidDriverConfigException here, when the driver is resolved, instead of
 * being silently cast at the use site: `(int) "abc"` is 0, and a ceiling of
 * zero would reject every circuit without ever saying why.
 *
 * Blank means absent, null or an empty string, which is what env() yields for
 * `AETHER_MAX_QUBITS=`; a blank option always falls back to its default.
 *
 * The raw array is kept verbatim for the JSON payload: the bin/python scripts
 * and custom providers read `driver_config` themselves, and may rely on keys
 * this class knows nothing about (`python_provider`, provider-specific
 * settings), so nothing is stripped or renamed on the way through.
 */
readonly class DriverConfig
{
    /**
     * Default number of qubits measured per shot when generating entropy.
     */
    public const DEFAULT_ENTROPY_QUBITS = 16;

    /**
     * Upper bound on qubits per circuit, or null for no ceiling.
     */
    public ?int $maxQubits;

    /**
     * Qubits measured per shot by generateEntropy(). Always positive.
     */
    public int $entropyQubits;

    /**
     * Whether a synchronous ->run() is allowed on this driver, or null to
     * derive from the driver's device ARN.
     */
    public ?bool $synchronousSafe;

    /**
     * @param  string  $driver  Driver identifier, used in exception messages.
     * @param  array<string, mixed>  $values  The raw `aether.drivers.<driver>` array.
     *
     * @throws InvalidDriverConfigException When an option has a value of the wrong shape.
     */
    public function __construct(
        public string $driver,
        private array $values,
    ) {
        $this->maxQubits = $this->positiveInteger('max_qubits', $this->get('max_qubits'));

        // A non-positive count would make generateEntropy() divide by zero.
        // The setting is non-critical, so it falls back to the default rather
        // than failing the whole request; a non-numeric value still throws.
        $entropyQubits = $this->integer('entropy_qubits', $this->get('entropy_qubits'));
        $this->entropyQubits = $entropyQubits === null || $entropyQubits <= 0
            ? self::DEFAULT_ENTROPY_QUBITS
            : $entropyQubits;

        $this->synchronousSafe = $this->boolean('synchronous_safe', $this->get('synchronous_safe'));
    }

    /**
     * Read a raw option, for keys this class does not type.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Whether the option is absent, null or an empty string.
     */
    public function isBlank(string $key): bool
    {
        return self::blank($this->get($key));
    }

    /**
     * Return the subset of $keys whose value is blank, preserving order.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function blankKeys(array $keys): array
    {
        return array_values(array_filter($keys, fn (string $key): bool => $this->isBlank($key)));
    }

    /**
     * The raw array exactly as configured, for the `driver_config` payload key.
     *
     * @return array<string, mixed>
     *
     * @deprecated Passing the raw array is a transitional mechanism for the Python bridge.
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Cast an optional positive integer (>= 1), or null when blank.
     *
     * @throws InvalidDriverConfigException
     */
    protected function positiveInteger(string $key, mixed $value): ?int
    {
        $filtered = $this->filtered($key, $value, FILTER_VALIDATE_INT, ['min_range' => 1], 'a positive integer or null');

        return $filtered === null ? null : (int) $filtered;
    }

    /**
     * Cast an optional integer of any sign, or null when blank.
     *
     * @throws InvalidDriverConfigException
     */
    protected function integer(string $key, mixed $value): ?int
    {
        $filtered = $this->filtered($key, $value, FILTER_VALIDATE_INT, [], 'an integer or null');

        return $filtered === null ? null : (int) $filtered;
    }

    /**
     * Cast an optional non-negative number (>= 0), or null when blank.
     *
     * @throws InvalidDriverConfigException
     */
    protected function nonNegativeNumber(string $key, mixed $value): ?float
    {
        $filtered = $this->filtered($key, $value, FILTER_VALIDATE_FLOAT, ['min_range' => 0], 'a non-negative number or null');

        return $filtered === null ? null : (float) $filtered;
    }

    /**
     * Cast an optional boolean, or null when blank.
     *
     * Accepts real booleans and the string/integer spellings env() produces
     * ("true", "false", "1", "0", "on", "off", "yes", "no").
     *
     * @throws InvalidDriverConfigException
     */
    protected function boolean(string $key, mixed $value): ?bool
    {
        if (self::blank($value)) {
            return null;
        }

        $filtered = is_scalar($value)
            ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        if ($filtered === null) {
            throw InvalidDriverConfigException::invalidValue($this->driver, $key, $value, 'a boolean or null');
        }

        return $filtered;
    }

    /**
     * Cast an optional string, or null when blank.
     *
     * @throws InvalidDriverConfigException
     */
    protected function string(string $key, mixed $value): ?string
    {
        if (self::blank($value)) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw InvalidDriverConfigException::invalidValue($this->driver, $key, $value, 'a string or null');
        }

        return (string) $value;
    }

    /**
     * Run a numeric option through filter_var, treating blank as unset.
     *
     * Booleans are refused explicitly because filter_var accepts true as 1,
     * and non-scalars (arrays, objects) never pass.
     *
     * @param  array<string, int|float>  $options
     *
     * @throws InvalidDriverConfigException When the value is neither blank nor accepted by the filter.
     */
    private function filtered(string $key, mixed $value, int $filter, array $options, string $expected): int|float|null
    {
        if (self::blank($value)) {
            return null;
        }

        $filtered = is_scalar($value) && ! is_bool($value)
            ? filter_var($value, $filter, ['options' => $options])
            : false;

        if ($filtered === false) {
            throw InvalidDriverConfigException::invalidValue($this->driver, $key, $value, $expected);
        }

        return $filtered;
    }

    /**
     * Whether a raw value counts as "not configured".
     */
    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
