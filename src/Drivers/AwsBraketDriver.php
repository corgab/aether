<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Exceptions\QuantumExecutionException;

/**
 * Quantum driver for AWS Braket QPU and managed simulators.
 */
class AwsBraketDriver extends AbstractQuantumDriver
{
    protected function driverName(): string
    {
        return 'aws';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfig(): array
    {
        return ['region', 'device_arn'];
    }

    protected function beforeExecution(): void
    {
        parent::beforeExecution();

        if (($this->config['synchronous_safe'] ?? true) === false) {
            throw QuantumExecutionException::synchronousUnsafe('aws');
        }
    }
}
