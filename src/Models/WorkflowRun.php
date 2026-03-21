<?php

namespace Cainy\Laragraph\Models;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int            $id
 * @property string|null    $key
 * @property array|null     $snapshot
 * @property array          $state
 * @property RunStatus      $status
 * @property string         $current
 * @property array          $active_pointers
 */
class WorkflowRun extends Model
{
    use SoftDeletes, MassPrunable;

    protected $fillable = [
        'key',
        'snapshot',
        'state',
        'status',
        'current',
        'active_pointers',
    ];

    protected $attributes = [
        'state'           => '{}',
        'status'          => RunStatus::Pending->value,
        'current'         => Workflow::START,
        'active_pointers' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'snapshot'        => 'array',
            'state'           => 'array',
            'status'          => RunStatus::class,
            'active_pointers' => 'array',
        ];
    }

    public function prunable(): Builder
    {
        $days = config('laragraph.prunable_after_days', 30);

        return static::where('created_at', '<', now()->subDays($days));
    }
}
