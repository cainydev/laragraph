<?php

namespace Cainy\Laragraph\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property string $node_name
 * @property int $attempt
 * @property array<string, string|int|float>|null $tags
 * @property Carbon $executed_at
 * @property string|null $error_class
 * @property string|null $error_message
 * @property string|null $error_trace
 * @property Carbon|null $failed_at
 */
class NodeExecution extends Model
{
    protected $table = 'workflow_node_executions';

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'node_name',
        'attempt',
        'tags',
        'executed_at',
        'error_class',
        'error_message',
        'error_trace',
        'failed_at',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }

    public function failed(): bool
    {
        return $this->failed_at !== null;
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'executed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
