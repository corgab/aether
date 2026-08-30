<?php

declare(strict_types=1);

namespace Aether\Models;

use Aether\Tasks\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persisted record of an asynchronously dispatched quantum task.
 *
 * @property int $id
 * @property string $task_arn
 * @property string $driver
 * @property TaskStatus $status
 * @property array<string, mixed> $circuit
 * @property array<string, int>|null $counts
 * @property int $shots
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class QuantumTask extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'circuit' => 'json',
            'counts' => 'json',
            'shots' => 'integer',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
