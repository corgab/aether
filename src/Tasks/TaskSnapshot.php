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
     * Pass $status when the caller has already validated the response's
     * `status` key (AbstractQuantumDriver::pollTask() does), so the value the
     * guard checked is the one the snapshot carries; otherwise the key is
     * parsed here.
     *
     * @param  array<mixed>  $response
     */
    public static function fromResponse(array $response, ?TaskStatus $status = null): self
    {
        $counts = $response['counts'] ?? null;

        /** @var array<string, int>|null $counts */
        $counts = is_array($counts) ? $counts : null;

        return new self(
            $status ?? TaskStatus::from((string) ($response['status'] ?? '')),
            $counts,
        );
    }
}
