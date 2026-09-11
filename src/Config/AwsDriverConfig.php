<?php

declare(strict_types=1);

namespace Aether\Config;

use Aether\Exceptions\InvalidDriverConfigException;

/**
 * Typed view over the `aws` driver's config: the shared options plus the
 * pricing rates and the per-run cost ceiling AwsBraketDriver enforces.
 *
 * Presence of `region`, `device_arn` and `bucket` is still checked lazily by
 * the driver (see AbstractQuantumDriver::requiredConfig()), so a driver can be
 * resolved for estimateCost() without a complete remote setup; this class only
 * rejects values that are present but of the wrong shape.
 */
readonly class AwsDriverConfig extends DriverConfig
{
    /**
     * Default currency reported by estimateCost() when none is configured.
     */
    public const DEFAULT_CURRENCY = 'USD';

    /**
     * AWS region (`region`), or null when not configured.
     */
    public ?string $region;

    /**
     * S3 bucket results are written to (`bucket`), or null to let the Braket
     * SDK fall back to its own default bucket.
     */
    public ?string $bucket;

    /**
     * Braket device ARN (`device_arn`), or null when not configured.
     */
    public ?string $deviceArn;

    /**
     * Estimated-cost ceiling for one ->run(), ->dispatch() or batch, or null for none.
     */
    public ?float $maxCostPerRun;

    /**
     * Price per task (`pricing.per_task`), or null when not configured.
     */
    public ?float $perTaskRate;

    /**
     * Price per shot (`pricing.per_shot`), or null when not configured.
     */
    public ?float $perShotRate;

    /**
     * Currency code for estimates (`pricing.currency`).
     */
    public string $currency;

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidDriverConfigException When an option has a value of the wrong shape.
     */
    public function __construct(string $driver, array $values)
    {
        parent::__construct($driver, $values);

        $this->region = $this->string('region', $this->get('region'));
        $this->bucket = $this->string('bucket', $this->get('bucket'));
        $this->deviceArn = $this->string('device_arn', $this->get('device_arn'));

        $this->maxCostPerRun = $this->nonNegativeNumber('max_cost_per_run', $this->get('max_cost_per_run'));

        $pricing = $this->get('pricing') ?? [];

        if (! is_array($pricing)) {
            throw InvalidDriverConfigException::invalidValue($driver, 'pricing', $pricing, 'an array of rates or null');
        }

        $this->perTaskRate = $this->nonNegativeNumber('pricing.per_task', $pricing['per_task'] ?? null);
        $this->perShotRate = $this->nonNegativeNumber('pricing.per_shot', $pricing['per_shot'] ?? null);
        $this->currency = $this->string('pricing.currency', $pricing['currency'] ?? null) ?? self::DEFAULT_CURRENCY;
    }

    /**
     * The `pricing.*` keys that are blank, in the order the guard reports them.
     *
     * @return list<string>
     */
    public function missingRates(): array
    {
        $missing = [];

        if ($this->perTaskRate === null) {
            $missing[] = 'pricing.per_task';
        }

        if ($this->perShotRate === null) {
            $missing[] = 'pricing.per_shot';
        }

        return $missing;
    }
}
