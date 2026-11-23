<?php

namespace App\Http\Controllers;

use App\Actions\Observability\BuildPublishObservabilityAction;
use App\Http\Requests\ObservabilityPeriodRequest;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

class ObservabilityController extends Controller
{
    /**
     * Publish observability: live success-rate aggregates per period.
     */
    public function index(
        BuildPublishObservabilityAction $buildPublishObservabilityAction,
        ObservabilityPeriodRequest $request,
    ): Response {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return Inertia::render('observability/index', [
            'metrics' => $buildPublishObservabilityAction
                ->handle($workspace, $request->period()),
        ]);
    }
}
