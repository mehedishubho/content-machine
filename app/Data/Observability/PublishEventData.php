<?php

namespace App\Data\Observability;

/**
 * One publish lifecycle event crossing into the recorder boundary (D-06).
 * Plain readonly DTO: constructor-promoted properties, no setters, no side
 * effects beyond the pure toArray() transformation.
 *
 * Payloads carry ids, stages, statuses, and durations only — never provider
 * API keys, workspace tokens, config objects, or content bodies (Pitfall 4,
 * ASVS V7).
 */
final readonly class PublishEventData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $workspaceId,
        public string $publishableType,
        public int $publishableId,
        public string $stage,
        public ?string $jobId = null,
        public ?string $operationId = null,
        public ?string $runToken = null,
        public ?string $groupKey = null,
        public ?string $provider = null,
        public ?int $durationMs = null,
        public array $payload = [],
    ) {}

    /**
     * The publish attempt was admitted under a durable row lease. The whole
     * content is claimed, so groupKey stays null unless the caller passes
     * extra payload context (e.g. lease_expired).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function claim(
        int $workspaceId,
        string $publishableType,
        int $publishableId,
        ?string $jobId = null,
        ?string $operationId = null,
        ?string $runToken = null,
        array $payload = [],
    ): self {
        return new self(
            workspaceId: $workspaceId,
            publishableType: $publishableType,
            publishableId: $publishableId,
            stage: 'claim',
            jobId: $jobId,
            operationId: $operationId,
            runToken: $runToken,
            payload: $payload,
        );
    }

    /**
     * A checkpoint transaction committed mid-run (group progress saved).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function checkpoint(
        int $workspaceId,
        string $publishableType,
        int $publishableId,
        string $groupKey,
        ?string $jobId = null,
        ?string $operationId = null,
        ?string $runToken = null,
        ?string $provider = null,
        array $payload = [],
    ): self {
        return new self(
            workspaceId: $workspaceId,
            publishableType: $publishableType,
            publishableId: $publishableId,
            stage: 'checkpoint',
            jobId: $jobId,
            operationId: $operationId,
            runToken: $runToken,
            groupKey: $groupKey,
            provider: $provider,
            payload: $payload,
        );
    }

    /**
     * The attempt failed and was handed back to the queue worker's retry
     * policy. reason is the exception class short name; attempt is the
     * worker's attempt count when known.
     */
    public static function retry(
        int $workspaceId,
        string $publishableType,
        int $publishableId,
        string $reason,
        ?int $attempt = null,
        ?string $jobId = null,
        ?string $operationId = null,
        ?string $runToken = null,
    ): self {
        return new self(
            workspaceId: $workspaceId,
            publishableType: $publishableType,
            publishableId: $publishableId,
            stage: 'retry',
            jobId: $jobId,
            operationId: $operationId,
            runToken: $runToken,
            payload: ['reason' => $reason, 'attempt' => $attempt],
        );
    }

    /**
     * The publish attempt reached its terminal outcome for this run
     * ('succeeded' or 'failed').
     */
    public static function finalize(
        int $workspaceId,
        string $publishableType,
        int $publishableId,
        string $outcome,
        ?string $jobId = null,
        ?string $operationId = null,
        ?string $runToken = null,
        ?int $durationMs = null,
    ): self {
        return new self(
            workspaceId: $workspaceId,
            publishableType: $publishableType,
            publishableId: $publishableId,
            stage: 'finalize',
            jobId: $jobId,
            operationId: $operationId,
            runToken: $runToken,
            durationMs: $durationMs,
            payload: ['outcome' => $outcome],
        );
    }

    /**
     * Snake_case projection used for BOTH the publish-channel log line
     * context and the publish_events row.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'publishable_type' => $this->publishableType,
            'publishable_id' => $this->publishableId,
            'stage' => $this->stage,
            'job_id' => $this->jobId,
            'operation_id' => $this->operationId,
            'run_token' => $this->runToken,
            'group_key' => $this->groupKey,
            'provider' => $this->provider,
            'duration_ms' => $this->durationMs,
            'payload' => $this->payload,
        ];
    }
}
