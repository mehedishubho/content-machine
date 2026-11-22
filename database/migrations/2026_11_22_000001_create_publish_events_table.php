<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `publish_events` is append-only: one row per observed publish lifecycle
     * event (claim, checkpoint, retry, finalize, provider_http), written
     * best-effort by App\Support\Observability\PublishEventRecorder AFTER the
     * enclosing publish transaction commits (D-07). Rows are never updated,
     * so there is deliberately no `updated_at` column, mirroring
     * status_transitions. Rows are deleted only by the 90-day retention
     * prune (D-03).
     *
     * `workspace_id` is set explicitly by the recorder: the
     * BelongsToWorkspace creating hook reads Workspace::current(), which is
     * only populated for HTTP requests and is null on queue workers.
     */
    public function up(): void
    {
        Schema::create('publish_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained();
            // post|video (D-05). nullableMorphs also creates the
            // (publishable_type, publishable_id) index used for per-content
            // history queries.
            $table->nullableMorphs('publishable');
            // claim|checkpoint|retry|finalize|provider_http.
            $table->string('stage');
            $table->string('job_id')->nullable();
            $table->string('operation_id')->nullable();
            $table->string('run_token')->nullable();
            $table->string('group_key')->nullable();
            $table->string('provider')->nullable();
            $table->integer('duration_ms')->nullable();
            // Correlation ids, stages, statuses, and durations only — never
            // config objects, tokens, or content bodies (Pitfall 4 / ASVS V7).
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            // Dashboard period aggregates (D-19).
            $table->index(['workspace_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publish_events');
    }
};
