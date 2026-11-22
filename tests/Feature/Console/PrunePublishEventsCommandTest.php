<?php

namespace Tests\Feature\Console;

use App\Models\PublishEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrunePublishEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_past_the_retention_boundary_and_keeps_every_newer_row(): void
    {
        // The frozen clock makes the boundary exact: the command's cutoff is
        // now - retention.days against the same now() the fixtures used, so
        // a row created exactly at the boundary survives the strict <.
        $this->travelTo(now());
        $workspace = Workspace::factory()->create();
        $outside = $this->seedEvent($workspace, 91);
        $boundary = $this->seedEvent($workspace, 90);
        $inside = $this->seedEvent($workspace, 89);
        $fresh = $this->seedEvent($workspace, 1);

        $this->artisan('publish-events:prune')
            ->expectsOutputToContain('Pruned 1 publish event(s) older than 90 day(s).')
            ->assertExitCode(0);

        $this->assertNull(PublishEvent::query()->find($outside->id));
        $this->assertNotNull(PublishEvent::query()->find($boundary->id));
        $this->assertNotNull(PublishEvent::query()->find($inside->id));
        $this->assertNotNull(PublishEvent::query()->find($fresh->id));
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $this->travelTo(now());
        $workspace = Workspace::factory()->create();
        $this->seedEvent($workspace, 91);
        $inside = $this->seedEvent($workspace, 10);

        $this->artisan('publish-events:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would prune 1 publish event(s) older than 90 day(s).')
            ->assertExitCode(0);

        $this->assertSame(2, PublishEvent::query()->count());
        $this->assertNotNull($inside->fresh());
    }

    public function test_a_workspace_scoped_aggregate_still_serves_after_a_prune(): void
    {
        $this->travelTo(now());
        $workspace = Workspace::factory()->create();
        $this->seedEvent($workspace, 91);
        $this->seedEvent($workspace, 30);
        $this->seedEvent($workspace, 7);

        $this->artisan('publish-events:prune')->assertExitCode(0);

        $aggregate = PublishEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->selectRaw("count(*) filter (where stage = 'provider_http') as events")
            ->first();

        $this->assertSame(2, (int) $aggregate->events);
    }

    /**
     * Seed one provider_http publish event at an exact age. created_at is
     * not fillable on the append-only model, so the fixture forceFills it;
     * workspace_id is set explicitly because Workspace::current() is null
     * in console context (Pitfall 1).
     */
    private function seedEvent(Workspace $workspace, int $ageDays): PublishEvent
    {
        $event = new PublishEvent([
            'workspace_id' => $workspace->id,
            'publishable_type' => null,
            'publishable_id' => null,
            'stage' => 'provider_http',
            'payload' => ['provider' => 'postsyncer', 'status' => 200],
        ]);
        $event->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $event;
    }
}
