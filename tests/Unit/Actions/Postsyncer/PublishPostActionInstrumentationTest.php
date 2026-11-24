<?php

namespace Tests\Unit\Actions\Postsyncer;

use App\Actions\Postsyncer\PublishPostAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPublishChannel;
use Tests\TestCase;

class PublishPostActionInstrumentationTest extends TestCase
{
    use FakesPublishChannel;
    use RefreshDatabase;

    private PublishPostAction $action;

    /**
     * @return array<string, mixed>
     */
    private function samplePostTypes(): array
    {
        return [
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on'],
            ],
            'overrides' => [],
        ];
    }

    private function configureWorkspace(Workspace $workspace): void
    {
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                    ],
                ],
            ],
            'post_types' => $this->samplePostTypes(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function postAttributes(): array
    {
        return [
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ];
    }

    /**
     * OBS-01: neither the publish-channel log contexts nor the publish_events
     * rows may ever serialize the workspace's PostSyncer API key.
     */
    private function assertNoWorkspaceApiKeyInAnyEvent(): void
    {
        $this->assertStringNotContainsString(
            'test-api-key',
            (string) json_encode($this->publishEventContexts()),
        );
        $this->assertStringNotContainsString(
            'test-api-key',
            (string) json_encode(PublishEvent::query()->orderBy('id')->get()->toArray()),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new PublishPostAction(new PostPublishPlanner(new MediaUrlResolver));
        $this->fakePublishChannel();
    }

    /**
     * (a) A successful publish emits claim -> checkpoint(s) -> finalize(succeeded)
     * on the publish channel in that stage order, with full correlation ids.
     */
    public function test_successful_publish_emits_claim_checkpoints_and_succeeded_finalize_in_stage_order(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 42, 'status' => 'published'], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create($this->postAttributes());

        $this->action->handle($post, ['confirm_ask' => false], runToken: 'run-token-42', jobId: 'job-42', attempt: 1);

        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);
        $this->assertContains('checkpoint', $stages);
        $this->assertSame('finalize', $stages[count($stages) - 1]);
        $this->assertSame(['claim', 'checkpoint', 'checkpoint', 'checkpoint', 'finalize'], $stages);
        $this->assertNotContains('retry', $stages);

        $contexts = $this->publishEventContexts();
        $finalize = end($contexts);
        $this->assertSame('succeeded', $finalize['payload']['outcome']);
        $this->assertGreaterThan(0, $finalize['duration_ms']);

        foreach ($this->publishEventContexts() as $context) {
            $this->assertSame($workspace->id, $context['workspace_id']);
            $this->assertSame(Post::class, $context['publishable_type']);
            $this->assertSame($post->getKey(), $context['publishable_id']);
            $this->assertSame('job-42', $context['job_id']);
            $this->assertNotNull($context['run_token']);
            if ($context['stage'] !== 'claim') {
                // prepareProgress assigns the operation id mid-run; the claim
                // event precedes it and carries only the dispatch param.
                $this->assertNotNull($context['operation_id']);
            }
            $this->assertNotFalse(json_encode($context));
        }

        $this->assertSame(5, PublishEvent::query()->count());
        $this->assertSame(5, count($this->publishEventRecords()));
    }

    /**
     * OBS-01 / ASVS V7: every payload key on the post publish path stays
     * inside the stage whitelist (ids and small status fields only), and no
     * event — log context or DB row — ever carries the workspace's
     * PostSyncer API key.
     */
    public function test_event_payloads_use_whitelisted_keys_only_and_never_carry_the_workspace_api_key(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 42, 'status' => 'published'], 201),
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'workspace_id' => 15211,
                'content' => [['text' => 'Caption', 'media' => []]],
                'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                    'post_type' => 'POST', 'caption' => 'Caption',
                ]]],
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create($this->postAttributes());

        $this->action->handle($post, ['confirm_ask' => false], runToken: 'run-token-42', jobId: 'job-42', attempt: 1);

        $contexts = $this->publishEventContexts();
        $rows = PublishEvent::query()->orderBy('id')->get();
        $this->assertGreaterThan(0, count($contexts));
        $this->assertCount(count($contexts), $rows);

        $allowedKeysByStage = [
            'claim' => ['lease_expired'],
            'checkpoint' => ['completed', 'group', 'phase'],
            'retry' => ['attempt', 'reason'],
            'finalize' => ['outcome'],
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

            if ($event['stage'] === 'finalize') {
                $this->assertSame(['outcome'], $keys);
            } elseif ($event['stage'] === 'claim') {
                $this->assertTrue($keys === [] || $keys === ['lease_expired']);
            } else {
                $this->assertSame([], array_diff($keys, $allowedKeysByStage[$event['stage']]));
            }
        }

        $this->assertStringNotContainsString('test-api-key', (string) json_encode($contexts));
        $this->assertStringNotContainsString('test-api-key', (string) json_encode($rows->toArray()));
    }

    /**
     * (b) A deterministic provider rejection (422) is kept in the record and
     * emits finalize(failed) — not a retry.
     */
    public function test_deterministic_create_rejection_emits_finalize_failed_without_retry(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'message' => 'Invalid account configuration',
            ], 422),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create($this->postAttributes());

        $this->action->handle($post, ['confirm_ask' => false], jobId: 'job-422');

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);
        $this->assertNotContains('retry', $stages);
        $this->assertSame('finalize', $stages[count($stages) - 1]);

        $contexts = $this->publishEventContexts();
        $finalize = end($contexts);
        $this->assertSame('failed', $finalize['payload']['outcome']);

        $this->assertNoWorkspaceApiKeyInAnyEvent();
    }

    /**
     * (b) A transient media failure (503) is handed back to the queue worker
     * and emits a retry event with the exception short name and attempt.
     */
    public function test_transient_media_failure_emits_retry_event_before_rethrow(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'message' => 'temporary outage',
            ], 503),
        ]);

        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            ...$this->postAttributes(),
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        try {
            $this->action->handle($post, ['confirm_ask' => false], jobId: 'job-503', attempt: 2);
            $this->fail('A retryable media failure must surface to the queue worker.');
        } catch (PostsyncerException) {
            // The queue-worker retry path this test exercises.
        }

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);
        $this->assertContains('checkpoint', $stages);
        $this->assertNotContains('finalize', $stages);
        $this->assertSame('retry', $stages[count($stages) - 1]);

        $contexts = $this->publishEventContexts();
        $retry = end($contexts);
        $this->assertSame('PostsyncerException', $retry['payload']['reason']);
        $this->assertSame(2, $retry['payload']['attempt']);
        $this->assertSame('job-503', $retry['job_id']);

        $this->assertNoWorkspaceApiKeyInAnyEvent();
    }

    /**
     * (c) An expired previous lease resets the run and emits a claim event
     * carrying lease_expired (OBS-05). Publish behavior is unchanged.
     */
    public function test_expired_lease_reset_emits_claim_event_with_lease_expired_payload(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create([
            ...$this->postAttributes(),
            'publish_state' => 'running',
            'publish_claimed_at' => now()->subSeconds(PublishPostJob::LEASE_SECONDS + 2000),
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-exp',
                'options' => ['when' => null, 'confirm_ask' => false],
                'plan_hash' => 'plan-1',
                'planned_groups' => [],
                'completed_groups' => [],
                'current' => [
                    'index' => 0,
                    'group_key' => 'group-1',
                    'phase' => 'creating',
                ],
                'state' => 'running',
            ],
        ]);

        $this->action->handle($post, ['confirm_ask' => false], 'run-exp');

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString(
            'The previous publish worker lease expired.',
            (string) $post->publish_error,
        );

        $stages = $this->publishEventStages();
        $this->assertSame(['claim'], $stages);

        $claim = $this->publishEventContexts()[0];
        $this->assertTrue($claim['payload']['lease_expired']);
        $this->assertSame('run-exp', $claim['run_token']);
        $this->assertSame($workspace->id, $claim['workspace_id']);

        $this->assertNoWorkspaceApiKeyInAnyEvent();
    }

    /**
     * (d) A publish whose finalize transaction rolls back leaves the
     * previously recorded claim/checkpoint events intact — event rows are
     * appended outside publish transactions (D-07), and the failure twin
     * finalize(failed) is still emitted after recordFailureForRun commits.
     */
    public function test_rolled_back_finalize_leaves_earlier_event_rows_and_emits_finalize_failed(): void
    {
        $workspace = Workspace::factory()->create();
        $this->configureWorkspace($workspace);
        $post = Post::factory()->for($workspace)->create($this->postAttributes());
        $changed = false;

        Http::fake(function ($request) use ($post, &$changed) {
            if ($request->url() === 'https://postsyncer.com/api/v1/posts' && ! $changed) {
                Post::query()->whereKey($post->id)->update([
                    'captions' => ['facebook' => 'Changed caption'],
                ]);
                $changed = true;

                return Http::response(['id' => 42, 'status' => 'published'], 201);
            }

            if ($request->url() === 'https://postsyncer.com/api/v1/posts/42') {
                return Http::response([
                    'id' => 42,
                    'workspace_id' => 15211,
                    'content' => [['text' => 'Caption', 'media' => []]],
                    'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => [
                        'post_type' => 'POST', 'caption' => 'Caption',
                    ]]],
                    'status' => 'PUBLISHED',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->action->handle($post, ['confirm_ask' => false], jobId: 'job-rollback');

        $post->refresh();
        $this->assertSame('failed', $post->publish_state);
        $this->assertStringContainsString('changed while', (string) $post->publish_error);

        $stages = $this->publishEventStages();
        $this->assertSame('claim', $stages[0]);
        $this->assertContains('checkpoint', $stages);
        $this->assertSame('finalize', $stages[count($stages) - 1]);

        $contexts = $this->publishEventContexts();
        $finalize = end($contexts);
        $this->assertSame('failed', $finalize['payload']['outcome']);

        // Every emitted event left an append-only row despite the finalize
        // transaction rolling back mid-publish (D-07 outside-transaction proof).
        $this->assertSame(
            count($this->publishEventRecords()),
            PublishEvent::query()->count(),
        );
        $this->assertGreaterThan(1, PublishEvent::query()->count());
    }
}
