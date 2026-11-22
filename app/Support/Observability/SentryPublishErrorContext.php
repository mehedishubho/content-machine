<?php

namespace App\Support\Observability;

use App\Contracts\PublishErrorContext;
use App\Data\Observability\PublishEventData;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Sentry implementation of the publish-context seam (D-10). Tags are
 * indexed and searchable in the Sentry UI; the `publish` context is
 * viewable on the issue page — workspace/stage tags plus the same ids the
 * publish log channel emits, so a Sentry issue maps 1:1 onto a
 * `grep publish_event` of the Railway logs.
 *
 * Payload discipline: ids only. Provider API keys, workspace tokens,
 * config objects, and content bodies never enter tags or context
 * (Pitfall 4, ASVS V7). Null correlation ids are omitted rather than
 * sent as nulls.
 *
 * Inert without a DSN: configureScope mutates the in-process scope and
 * the SDK's null-DSN transport drops every event, so attaching here
 * neither throws nor emits anything while SENTRY_LARAVEL_DSN is unset.
 */
final class SentryPublishErrorContext implements PublishErrorContext
{
    public function attach(PublishEventData $event): void
    {
        configureScope(function (Scope $scope) use ($event): void {
            $scope->setTag('workspace_id', (string) $event->workspaceId);
            $scope->setTag('publish_stage', $event->stage);
            $scope->setContext('publish', array_filter([
                'workspace_id' => $event->workspaceId,
                'content_id' => $event->publishableId,
                'content_type' => $event->publishableType,
                'job_id' => $event->jobId,
                'operation_id' => $event->operationId,
                'run_token' => $event->runToken,
            ], static fn ($value): bool => $value !== null));
        });
    }
}
