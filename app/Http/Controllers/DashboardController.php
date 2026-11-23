<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\BuildDashboardSummaryAction;
use App\Actions\Observability\BuildPublishObservabilityAction;
use App\Http\Requests\ObservabilityPeriodRequest;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Workspace home: pipeline counts, the next scheduled items, and the
     * compact publish-health figures (D-17).
     */
    public function home(
        BuildDashboardSummaryAction $buildDashboardSummaryAction,
        BuildPublishObservabilityAction $buildPublishObservabilityAction,
    ): Response {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        // The observability Action is the single aggregate home (D-19): the
        // card reuses its 7-day-default numbers instead of new queries.
        $publishHealth = $buildPublishObservabilityAction->handle(
            $workspace,
            ObservabilityPeriodRequest::DEFAULT_PERIOD,
        );

        return Inertia::render('dashboard/home', [
            ...$buildDashboardSummaryAction->handle($workspace)->toArray(),
            'publish_health' => [
                'rate' => $publishHealth['rate'],
                'failed' => $publishHealth['failed'],
                'retried' => $publishHealth['retries'],
            ],
        ]);
    }
}
