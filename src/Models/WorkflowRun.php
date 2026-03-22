<?php

namespace Cainy\Laragraph\Models;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Exceptions\InvalidStatusTransition;
use Cainy\Laragraph\Facades\Laragraph;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

/**
 * @property int $id
 * @property int|null $parent_run_id
 * @property string|null $parent_node_name
 * @property string|null $key
 * @property array|null $snapshot
 * @property array $state
 * @property RunStatus $status
 * @property string $current
 * @property array $active_pointers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class WorkflowRun extends Model
{
    use MassPrunable, SoftDeletes;

    protected $fillable = [
        'parent_run_id',
        'parent_node_name',
        'key',
        'snapshot',
        'state',
        'status',
        'current',
        'active_pointers',
    ];

    protected $attributes = [
        'state' => '{}',
        'status' => RunStatus::Pending->value,
        'current' => Workflow::START,
        'active_pointers' => '[]',
    ];

    /**
     * Pause this workflow run. Only runs with status "running" can be paused.
     *
     * @throws InvalidStatusTransition If the run is not currently 'running'.
     * @throws Throwable For underlying database or transaction failures.
     */
    public function pause(): self
    {
        return Laragraph::pause($this->id);
    }

    /**
     * Abort this workflow run. Aborting sets the run status to "failed"
     * and clears all active pointers, effectively halting execution.
     *
     * @throws Throwable
     */
    public function abort(): self
    {
        return Laragraph::abort($this->id);
    }

    /**
     * Resume this workflow run. Only runs with status "paused" can be resumed.
     * Optionally, additional state can be merged into the run's state.
     *
     * @throws InvalidStatusTransition If the run is not currently 'paused'.
     * @throws Throwable For underlying database or transaction failures.
     */
    public function resume(array $additionalState = []): self
    {
        return Laragraph::resume($this->id, $additionalState);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    public function prunable(): Builder
    {
        $days = config('laragraph.prunable_after_days', 30);

        return static::query()->where('created_at', '<', now()->subDays($days));
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'state' => 'array',
            'status' => RunStatus::class,
            'active_pointers' => 'array',
        ];
    }
}
