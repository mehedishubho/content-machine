<?php

namespace Tests\Unit\Actions\Socialreins;

use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPublishChannel;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

class PublishToSocialreinsInstrumentationTest extends TestCase
{
    use FakesPublicDns;
    use FakesPublishChannel;
    use RefreshDatabase;

    private const URL = 'https://brain.test/v1/post-groups';

    private const EN_SET = '11111111-1111-4111-8111-111111111111';

    private PublishToSocialreinsAction $action;

    private function workspace(): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, ['post_types' => [
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on'],
            ],
        ]]);
        SocialreinsConfig::write($workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'webhook_secret' => 'whsec_test',
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => self::EN_SET, 'token' => 'sr_set_live_english'],
            ],
        ]);

        return $workspace->fresh();
    }

    private function makePost(Workspace $workspace): Post
    {
        return Post::factory()->for($workspace)->create([
            'publish_state' => 'queued',
            'language' => 'en',
            'platforms' => ['facebook'],
            'captions' => ['English' => ['facebook' => ['caption' => 'Hello']]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveHostsTo();
        $this->action = app(PublishToSocialreinsAction::class);
        $this->fakePublishChannel();
    }

    /**
     * A SocialReins publish run emits claim -> checkpoint -> finalize
     * (succeeded) in that stage order, with the queue job id on every event.
     */
    public function test_successful_run_emits_claim_checkpoint_and_succeeded_finalize(): void
    {
        $this->fakeHttp([
            self::URL => Http::response([
                'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
                'expected_targets' => 1,
                'posts' => [[
                    'id' => 'bbbbbbbb-0000-4000-8000-000000000000',
                    'platform' => 'facebook',
                    'status' => 'scheduled',
                    'error' => null,
                    'targets' => [],
                ]],
                'skipped' => [],
            ], 201),
        ]);

        $workspace = $this->workspace();
        $post = $this->makePost($workspace);

        $this->action->handle($post, [], 'job-sr-1');

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state, (string) $post->publish_error);

        $stages = $this->publishEventStages();
        $this->assertSame(['claim', 'checkpoint', 'finalize'], $stages);

        $contexts = $this->publishEventContexts();
        $claim = $contexts[0];
        $this->assertSame('job-sr-1', $claim['job_id']);
        $this->assertSame($workspace->id, $claim['workspace_id']);
        $this->assertSame(Post::class, $claim['publishable_type']);
        $this->assertNull($claim['run_token']);

        $checkpoint = $contexts[1];
        $this->assertSame('english', $checkpoint['group_key']);
        $this->assertSame('socialreins', $checkpoint['provider']);
        $this->assertSame(['group' => 'english', 'completed' => 1], $checkpoint['payload']);

        $finalize = $contexts[2];
        $this->assertSame('succeeded', $finalize['payload']['outcome']);
        $this->assertSame('job-sr-1', $finalize['job_id']);

        $this->assertSame(3, PublishEvent::query()->count());
    }

    /**
     * A failed SocialReins run (brain 500, answer lost) emits finalize(failed)
     * once the subject settles to failed, with no confirmed checkpoint.
     */
    public function test_failed_run_emits_finalize_failed_without_a_confirmed_checkpoint(): void
    {
        $this->fakeHttp([
            self::URL => Http::response(['error' => 'internal'], 500),
        ]);

        $workspace = $this->workspace();
        $post = $this->makePost($workspace);

        $this->action->handle($post, [], 'job-sr-2');

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('SocialReins answered 500', (string) $post->publish_error);

        $stages = $this->publishEventStages();
        // The wired client emits the raw 500 provider_http outcome between
        // the claim and the failure finalize.
        $this->assertSame(['claim', 'provider_http', 'finalize'], $stages);
        $this->assertSame('claim', $stages[0]);
        $this->assertSame(500, $this->publishEventContexts()[1]['payload']['status']);

        $contexts = $this->publishEventContexts();
        $finalize = $contexts[count($contexts) - 1];
        $this->assertSame('failed', $finalize['payload']['outcome']);
        $this->assertSame('job-sr-2', $finalize['job_id']);
        $this->assertSame(3, PublishEvent::query()->count());
    }

    /**
     * markFailed — the failure exit shared by both catches and the job's
     * failed callback — emits finalize(failed) after its transaction returns,
     * with the job id as correlation.
     */
    public function test_mark_failed_emits_finalize_failed_with_job_correlation(): void
    {
        $workspace = $this->workspace();
        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'queued',
            'language' => 'en',
            'platforms' => ['facebook'],
            'captions' => ['English' => ['facebook' => ['caption' => 'Hello']]],
        ]);

        $this->assertTrue($this->action->markFailed($post, 'The SocialReins publish job failed.', 'job-sr-3'));

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);

        $this->assertSame(['finalize'], $this->publishEventStages());
        $contexts = $this->publishEventContexts();
        $finalize = $contexts[0];
        $this->assertSame('failed', $finalize['payload']['outcome']);
        $this->assertSame('job-sr-3', $finalize['job_id']);
    }
}
