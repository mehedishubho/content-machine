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
     * OBS-01 / ASVS V7: every payload key on the SocialReins publish path
     * stays inside the stage vocabulary whitelist (ids and small status
     * fields only), and no event — log context or DB row — ever carries the
     * workspace's webhook secret or set token.
     */
    public function test_event_payloads_use_whitelisted_keys_only_and_never_carry_the_webhook_secret_or_set_token(): void
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

        $this->action->handle($post, [], 'job-sr-sec');

        $contexts = $this->publishEventContexts();
        $rows = PublishEvent::query()->orderBy('id')->get();
        $this->assertGreaterThan(0, count($contexts));
        $this->assertCount(count($contexts), $rows);

        $allowedKeysByStage = [
            'claim' => ['lease_expired'],
            'checkpoint' => ['completed', 'group', 'phase'],
            'retry' => ['attempt', 'reason'],
            'finalize' => ['outcome'],
            'provider_http' => ['path', 'provider', 'status', 'waited_seconds'],
        ];

        $observed = [];
        foreach ($contexts as $context) {
            $observed[] = ['stage' => (string) $context['stage'], 'payload' => $context['payload']];
        }
        foreach ($rows as $row) {
            $observed[] = ['stage' => (string) $row->stage, 'payload' => $row->payload];
        }

        foreach ($observed as $event) {
            $this->assertArrayHasKey($event['stage'], $allowedKeysByStage);

            $keys = array_keys($event['payload']);
            sort($keys);

            if (in_array($event['stage'], ['finalize', 'provider_http'], true)) {
                if ($event['stage'] === 'provider_http') {
                    $this->assertSame(['path', 'provider', 'status', 'waited_seconds'], $keys);
                } else {
                    $this->assertSame(['outcome'], $keys);
                }
            } else {
                $this->assertSame([], array_diff($keys, $allowedKeysByStage[$event['stage']]));
            }
        }

        $serialized = (string) json_encode($contexts).(string) json_encode($rows->toArray());
        $this->assertStringNotContainsString('whsec_test', $serialized);
        $this->assertStringNotContainsString('sr_set_live_english', $serialized);
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
