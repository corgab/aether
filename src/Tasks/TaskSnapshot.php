<?php

declare(strict_types=1);

namespace Aether\Tasks;

/**
 * Immutable point-in-time view of an asynchronous quantum task.
 */
final readonly class TaskSnapshot
{
    /**
     * @param  array<string, int>|null  $counts  Measurement counts, present only once the task completed.
     */
    public function __construct(
        public TaskStatus $status,
        public ?array $counts = null,
    ) {}

    /**
     * Build a snapshot from a decoded check-script response.
     *
     * @param  array<mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        return new self(
            TaskStatus::from((string) ($response['status'] ?? '')),
            isset($response['counts']) && is_array($response['counts']) ? $response['counts'] : null,
        );
    }
}
