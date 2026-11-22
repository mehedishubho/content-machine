<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only: one row per observed publish lifecycle event, written by
 * PublishEventRecorder after the enclosing publish transaction commits
 * (D-07). Rows are never updated and are pruned only by the retention
 * command, so the model has no `updated_at` column and callers should
 * never call ->update() on a row.
 *
 * The recorder sets workspace_id explicitly on every create: the
 * BelongsToWorkspace creating hook reads Workspace::current(), which is
 * only populated by HTTP middleware and is null on queue workers. The
 * trait is used here for its dashboard-read global scope.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string|null $publishable_type
 * @property int|null $publishable_id
 * @property string $stage
 * @property string|null $job_id
 * @property string|null $operation_id
 * @property string|null $run_token
 * @property string|null $group_key
 * @property string|null $provider
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $created_at
 */
class PublishEvent extends Model
{
    use BelongsToWorkspace;

    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'publishable_type',
        'publishable_id',
        'stage',
        'job_id',
        'operation_id',
        'run_token',
        'group_key',
        'provider',
        'duration_ms',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'payload' => 'array',
        ];
    }

    /**
     * The published content this event belongs to (post or video).
     *
     * @return MorphTo<Model, $this>
     */
    public function publishable(): MorphTo
    {
        return $this->morphTo();
    }
}
