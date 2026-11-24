<?php

namespace Tests\Feature;

use App\Models\Idea;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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
        $this->get(route('dashboard.home'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_pipeline_summaries(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        ScratchpadEntry::factory()->for($workspace)->count(3)->create();
        Idea::factory()->for($workspace)->create(['kind' => 'video', 'status' => 'open']);
        Video::factory()->for($workspace)->create(['status' => 'recorded']);
        Post::factory()->for($workspace)->create(['status' => 'draft']);

        $this->get(route('dashboard.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard/home')
                ->where('scratchpad.untriaged', 3)
                ->where('videos.ideation', 1)
                ->where('videos.recorded', 1)
                ->where('videos.total', 1)
                ->where('posts.draft', 1)
                ->where('upcoming', []));
    }

    public function test_the_home_page_carries_the_publish_health_figures(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $succeededPost = Post::factory()->for($workspace)->create();
        $failedPost = Post::factory()->for($workspace)->create();
        $this->finalizeEvent($workspace, $succeededPost, 'succeeded');
        $this->finalizeEvent($workspace, $failedPost, 'failed');

        $this->get(route('dashboard.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('publish_health.rate', 50)
                ->where('publish_health.failed', 1)
                ->where('publish_health.retried', 0));
    }

    public function test_a_zero_event_workspace_gets_a_null_publish_health_rate(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get(route('dashboard.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('publish_health.rate', null)
                ->where('publish_health.failed', 0)
                ->where('publish_health.retried', 0));
    }

    /**
     * OBS-03 / T-01-09: the home "Publish health" card only counts the
     * acting owner's workspace — a second workspace's successes never
     * inflate the rate or deflate the failure count.
     */
    public function test_the_home_publish_health_card_ignores_another_workspaces_events(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $otherWorkspace = Workspace::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $otherPost = Post::factory()->for($otherWorkspace)->create(['title' => 'Other '.$i]);
            $this->finalizeEvent($otherWorkspace, $otherPost, 'succeeded');
        }

        $succeededPost = Post::factory()->for($workspace)->create();
        $failedPost = Post::factory()->for($workspace)->create();
        $this->finalizeEvent($workspace, $succeededPost, 'succeeded');
        $this->finalizeEvent($workspace, $failedPost, 'failed');

        $this->get(route('dashboard.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('publish_health.rate', 50)
                ->where('publish_health.failed', 1));
    }

    /**
     * One finalize-stage event row. created_at is not fillable on the
     * append-only model, so the fixture forceFills it.
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
