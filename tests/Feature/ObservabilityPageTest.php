<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservabilityPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingAsWorkspaceOwner(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard.observability.index'))
            ->assertRedirect(route('login'));
    }

    public function test_the_page_serves_seeded_metrics_for_the_default_period(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $succeededPost = Post::factory()->for($workspace)->create(['title' => 'Launch teaser']);
        $failedPost = Post::factory()->for($workspace)->create(['title' => 'Broken embed']);
        $this->finalizeEvent($workspace, $succeededPost, 'succeeded');
        $this->finalizeEvent($workspace, $failedPost, 'failed');

        $this->get(route('dashboard.observability.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('observability/index')
                ->where('metrics.period', '7')
                ->where('metrics.period_label', 'Last 7 days')
                ->where('metrics.attempts', 2)
                ->where('metrics.succeeded', 1)
                ->where('metrics.failed', 1)
                ->where('metrics.rate', 50)
                ->has('metrics.recent_outcomes', 2)
                ->where('metrics.recent_outcomes.0.outcome', 'failed')
                ->where('metrics.recent_outcomes.0.human_id', $failedPost->human_id)
                ->where('metrics.recent_outcomes.0.title', 'Broken embed'));
    }

    public function test_the_period_query_param_changes_the_period_prop(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $post = Post::factory()->for($workspace)->create();
        $this->finalizeEvent($workspace, $post, 'succeeded');

        $this->get(route('dashboard.observability.index', ['period' => '30']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('observability/index')
                ->where('metrics.period', '30')
                ->where('metrics.period_label', 'Last 30 days')
                ->where('metrics.attempts', 1));
    }

    public function test_an_invalid_period_falls_back_to_the_seven_day_default(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $post = Post::factory()->for($workspace)->create();
        $this->finalizeEvent($workspace, $post, 'succeeded');

        $this->get(route('dashboard.observability.index', ['period' => '999']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('observability/index')
                ->where('metrics.period', '7')
                ->where('metrics.period_label', 'Last 7 days'));

        $this->get(route('dashboard.observability.index', ['period' => 'drop table publish_events']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.period', '7'));
    }

    public function test_a_zero_event_workspace_gets_the_zero_state_props(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get(route('dashboard.observability.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('observability/index')
                ->where('metrics.attempts', 0)
                ->where('metrics.succeeded', 0)
                ->where('metrics.failed', 0)
                ->where('metrics.rate', null)
                ->where('metrics.recent_outcomes', []));
    }

    /**
     * One finalize-stage event row. created_at is not fillable on the
     * append-only model, so the fixture forceFills it; workspace_id is
     * explicit because Workspace::current() is null in test context.
     */
    private function finalizeEvent(
        Workspace $workspace,
        Post $publishable,
        string $outcome,
    ): PublishEvent {
        $event = new PublishEvent([
            'workspace_id' => $workspace->id,
            'publishable_type' => $publishable::class,
            'publishable_id' => (int) $publishable->getKey(),
            'stage' => 'finalize',
            'payload' => ['outcome' => $outcome],
        ]);
        $event->forceFill(['created_at' => now()])->save();

        return $event;
    }
}
