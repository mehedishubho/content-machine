<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\LinkResolution\PublicUrlGuard;
use App\Support\Observability\PublishEventRecorder;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesPublishChannel;
use Tests\TestCase;

class PostsyncerClientInstrumentationTest extends TestCase
{
    use FakesPublishChannel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            PublicUrlGuard::class,
            new PublicUrlGuard(fn (): array => [['ip' => '142.250.0.1']]),
        );
        $this->fakePublishChannel();
    }

    private function instrumentedClient(Workspace $workspace): PostsyncerClient
    {
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
        ]);

        return new PostsyncerClient(
            PostsyncerConfig::fromWorkspace($workspace->refresh()),
            rateLimitWaitSeconds: PostsyncerClient::PUBLISH_RATE_LIMIT_WAIT_SECONDS,
            recorder: new PublishEventRecorder,
            workspaceId: $workspace->id,
        );
    }

    /**
     * A throttled request emits the provider_http wait event with the backoff
     * waited seconds, plus the raw 429 outcome event, before the request is
     * resent — behavior unchanged (same exception types, budget math, retry
     * cadence).
     */
    public function test_a_429_wait_emits_a_provider_http_event_with_waited_seconds(): void
    {
        Sleep::fake();
        $workspace = Workspace::factory()->create();
        $client = $this->instrumentedClient($workspace);
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::sequence()
                ->push(['message' => 'Too Many Attempts.'], 429)
                ->push(['media' => [['id' => 915]], 'count_stored' => 1], 200),
        ]);

        $ids = $client->uploadFromUrls(15211, ['https://example.com/a.png']);
        $this->assertSame([915], $ids);

        $events = array_values(array_filter(
            $this->publishEventContexts(),
            fn (array $context): bool => $context['stage'] === 'provider_http',
        ));

        // One raw 429 outcome event (waited null) and one wait event (the
        // backoff waited seconds), in that order.
        $this->assertCount(2, $events);
        $this->assertSame(429, $events[0]['payload']['status']);
        $this->assertNull($events[0]['payload']['waited_seconds']);
        $this->assertSame('/media/upload/url', $events[0]['payload']['path']);
        $this->assertSame(429, $events[1]['payload']['status']);
        $this->assertSame(30, $events[1]['payload']['waited_seconds']);
        $this->assertSame('postsyncer', $events[1]['provider']);
        $this->assertSame($workspace->id, $events[1]['workspace_id']);
        $this->assertNull($events[1]['publishable_type']);
        $this->assertNull($events[1]['publishable_id']);

        $this->assertSame(2, PublishEvent::query()->where('stage', 'provider_http')->count());
    }

    /**
     * A 5xx response emits the provider_http event with the actual status
     * before the exception propagates unchanged.
     */
    public function test_a_5xx_response_emits_a_provider_http_event_with_the_actual_status(): void
    {
        $workspace = Workspace::factory()->create();
        $client = $this->instrumentedClient($workspace);
        Http::fake([
            'postsyncer.com/api/v1/workspaces' => Http::response(['message' => 'boom'], 500),
        ]);

        try {
            $client->listWorkspaces();
            $this->fail('Expected the 500 to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertSame(500, $exception->getCode());
        }

        $this->assertSame(['provider_http'], $this->publishEventStages());
        $event = $this->publishEventContexts()[0];
        $this->assertSame(500, $event['payload']['status']);
        $this->assertNull($event['payload']['waited_seconds']);
        $this->assertSame('/workspaces', $event['payload']['path']);
    }

    /**
     * A connection timeout emits the provider_http event with status 0 and
     * no waited seconds before the exception propagates unchanged.
     */
    public function test_a_connection_timeout_emits_a_provider_http_event_with_status_zero(): void
    {
        $workspace = Workspace::factory()->create();
        $client = $this->instrumentedClient($workspace);
        Http::fake([
            'postsyncer.com/*' => fn () => throw new ConnectionException('cURL error 28'),
        ]);

        try {
            $client->listWorkspaces();
            $this->fail('Expected the connection failure to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertSame(0, $exception->getCode());
            $this->assertStringContainsString('Could not reach PostSyncer', $exception->getMessage());
        }

        $this->assertSame(['provider_http'], $this->publishEventStages());
        $event = $this->publishEventContexts()[0];
        $this->assertSame(0, $event['payload']['status']);
        $this->assertNull($event['payload']['waited_seconds']);
    }

    /**
     * A client without the recorder (every unmodified call site) emits
     * nothing — the nullsafe guard short-circuits.
     */
    public function test_a_client_without_a_recorder_emits_no_events(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
        ]);
        $client = new PostsyncerClient(PostsyncerConfig::fromWorkspace($workspace->refresh()));
        Http::fake([
            'postsyncer.com/api/v1/workspaces' => Http::response(['message' => 'boom'], 500),
        ]);

        try {
            $client->listWorkspaces();
            $this->fail('Expected the 500 to throw.');
        } catch (PostsyncerException) {
            // Behavior unchanged for unmodified call sites.
        }

        $this->assertSame([], $this->publishEventStages());
        $this->assertSame(0, PublishEvent::query()->count());
    }
}
