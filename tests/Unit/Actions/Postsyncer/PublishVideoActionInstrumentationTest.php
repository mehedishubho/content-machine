<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\PublishEvent;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\LinkResolution\PublicUrlGuard;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\VideoPublishPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPublishChannel;
use Tests\TestCase;

class PublishVideoActionInstrumentationTest extends TestCase
{
    use FakesPublishChannel;
    use RefreshDatabase;

    private PublishVideoAction $action;

    /**
     * @return array<string, mixed>
     */
    private function samplePostTypes(): array
    {
        return [
            'platforms' => [
                'facebook' => ['reel' => 'on'],
                'instagram' => ['reel' => 'on'],
            ],
            'overrides' => [],
        ];
    }

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                        'instagram' => ['account_id' => 101, 'handle' => '@harun.ig'],
                    ],
                ],
            ],
            'post_types' => $this->samplePostTypes(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function videoAttributes(): array
    {
        return [
            'status' => 'recorded',
            'publish_state' => 'queued',
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB reel caption'],
                    'instagram' => ['caption' => 'IG reel caption'],
                ],
            ],
        ];
    }

    private function makeVideo(Workspace $workspace): Video
    {
        return Video::factory()->for($workspace)->create($this->videoAttributes());
    }

    private function fakeHappyProvider(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => fn () => Http::response('fake-media-bytes', 200),
            'upload.postsyncer.com/api/v1/media/upload/file' => Http::sequence()
                ->push(['media' => ['id' => 915]], 201)
                ->push(['media' => ['id' => 916]], 201),
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 42, 'status' => 'published'], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [[
                    'text' => 'FB reel caption',
                    'media' => [['id' => 915]],
                    'cover_image' => ['thumbnail' => ['id' => 916]],
                ]],
                'platforms' => [
                    ['platform' => 'facebook', 'settings' => ['post_type' => 'REELS']],
                    ['platform' => 'instagram', 'settings' => ['post_type' => 'REELS', 'caption' => 'IG reel caption']],
                ],
                'status' => 'PUBLISHED',
            ], 200),
            'postsyncer.com/api/v1/analytics/posts/42' => Http::response([
                'accounts' => [
                    ['platform' => 'facebook', 'account_id' => 100],
                    ['platform' => 'instagram', 'account_id' => 101],
                ],
            ], 200),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            PublicUrlGuard::class,
            new PublicUrlGuard(fn (): array => [['ip' => '142.250.0.1']]),
        );
        $this->action = new PublishVideoAction(new VideoPublishPlanner(new MediaUrlResolver));
        $this->fakePublishChannel();
    }

    /**
     * A video publish run emits the claim event on the publish channel with
     * the queue job id and run token as correlation ids, and both land as
     * persisted columns.
     */
    public function test_publish_run_emits_claim_event_with_job_id_and_run_token(): void
    {
        $this->fakeHappyProvider();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = $this->makeVideo($workspace);

        $this->action->handle($video, ['platforms' => ['facebook', 'instagram'], 'confirm_ask' => false], runToken: 'run-vid-1', jobId: 'job-vid-1');

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state, (string) $video->publish_error);

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);

        $claim = $this->publishEventContexts()[0];
        $this->assertSame('job-vid-1', $claim['job_id']);
        $this->assertSame('run-vid-1', $claim['run_token']);
        $this->assertSame($workspace->id, $claim['workspace_id']);
        $this->assertSame(Video::class, $claim['publishable_type']);
        $this->assertSame($video->getKey(), $claim['publishable_id']);
        $this->assertNotFalse(json_encode($claim));

        $row = PublishEvent::query()->oldest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('claim', $row->stage);
        $this->assertSame('job-vid-1', $row->job_id);
        $this->assertSame('run-vid-1', $row->run_token);
    }

    /**
     * A run carrying no job id (direct/legacy delivery) still emits the claim
     * event and persists it with nullable correlation columns (OBS-01 empty
     * edge).
     */
    public function test_a_run_without_a_job_id_still_emits_the_claim_event(): void
    {
        $this->fakeHappyProvider();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = $this->makeVideo($workspace);

        $this->action->handle($video, ['platforms' => ['facebook', 'instagram'], 'confirm_ask' => false]);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state, (string) $video->publish_error);

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);

        $claim = $this->publishEventContexts()[0];
        $this->assertNull($claim['job_id']);
        $this->assertNotNull($claim['run_token']);

        $row = PublishEvent::query()->oldest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('claim', $row->stage);
        $this->assertNull($row->job_id);
        $this->assertNotNull($row->run_token);
    }

    /**
     * A successful video publish emits claim -> checkpoints -> finalize
     * (succeeded) in that stage order, with full correlation ids on every
     * event and a provider-tagged checkpoint payload.
     */
    public function test_successful_publish_emits_claim_checkpoints_and_succeeded_finalize_in_stage_order(): void
    {
        $this->fakeHappyProvider();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = $this->makeVideo($workspace);

        $this->action->handle($video, ['platforms' => ['facebook', 'instagram'], 'confirm_ask' => false], runToken: 'run-vid-2', jobId: 'job-vid-2', attempt: 1);

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state, (string) $video->publish_error);

        $stages = $this->publishEventStages();
        $this->assertSame(
            ['claim', 'checkpoint', 'checkpoint', 'checkpoint', 'checkpoint', 'finalize'],
            $stages,
        );

        $contexts = $this->publishEventContexts();
        $finalize = end($contexts);
        $this->assertSame('succeeded', $finalize['payload']['outcome']);
        $this->assertSame('job-vid-2', $finalize['job_id']);
        $this->assertNotNull($finalize['operation_id']);

        foreach ($this->publishEventContexts() as $context) {
            $this->assertSame($workspace->id, $context['workspace_id']);
            $this->assertSame(Video::class, $context['publishable_type']);
            $this->assertSame($video->getKey(), $context['publishable_id']);
            $this->assertSame('run-vid-2', $context['run_token']);
            if ($context['stage'] === 'checkpoint') {
                $this->assertSame('postsyncer', $context['provider']);
            }
            $this->assertNotFalse(json_encode($context));
        }

        $this->assertSame(6, PublishEvent::query()->count());
    }

    /**
     * A deterministic provider rejection (422 on the media upload) is kept in
     * the record and emits finalize(failed) — not a retry — mirroring the post
     * path's failure twin.
     */
    public function test_deterministic_failure_emits_finalize_failed_without_retry(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => fn () => Http::response('fake-media-bytes', 200),
            'upload.postsyncer.com/api/upload/file' => Http::response(['message' => 'bad'], 422),
            'upload.postsyncer.com/api/v1/media/upload/file' => Http::response(['message' => 'bad'], 422),
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'bad'], 422),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = $this->makeVideo($workspace);

        $this->action->handle($video, ['platforms' => ['facebook', 'instagram'], 'confirm_ask' => false], runToken: 'run-vid-3', jobId: 'job-vid-3');

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);
        $this->assertNotContains('retry', $stages);
        $this->assertSame('finalize', $stages[count($stages) - 1]);

        $contexts = $this->publishEventContexts();
        $finalize = $contexts[count($contexts) - 1];
        $this->assertSame('failed', $finalize['payload']['outcome']);
    }

    /**
     * Payloads carry whitelisted keys only — no bodies, tokens, or keys ever
     * enter the event payloads (Pitfall 4 / ASVS V7).
     */
    public function test_event_payloads_carry_whitelisted_keys_only(): void
    {
        $this->fakeHappyProvider();

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $video = $this->makeVideo($workspace);

        $this->action->handle($video, ['platforms' => ['facebook', 'instagram'], 'confirm_ask' => false], runToken: 'run-vid-4', jobId: 'job-vid-4');

        $whitelist = [
            'claim' => [],
            'checkpoint' => ['completed', 'group', 'phase'],
            'retry' => ['attempt', 'reason'],
            'finalize' => ['outcome'],
        ];

        foreach ($this->publishEventContexts() as $context) {
            $keys = array_keys((array) $context['payload']);
            sort($keys);
            $this->assertContains($context['stage'], array_keys($whitelist));
            $this->assertSame(
                [],
                array_diff($keys, $whitelist[$context['stage']]),
                'Payload keys must stay inside the stage whitelist',
            );
            foreach (['body', 'token', 'key'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, (array) $context['payload']);
            }
        }
    }
}
