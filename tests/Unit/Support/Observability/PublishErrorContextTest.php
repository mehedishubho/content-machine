<?php

namespace Tests\Unit\Support\Observability;

use App\Contracts\PublishErrorContext;
use App\Data\Observability\PublishEventData;
use App\Models\Post;
use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Observability\PublishEventRecorder;
use App\Support\Observability\SentryPublishErrorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\Concerns\FakesPublishChannel;
use Tests\TestCase;

class PublishErrorContextTest extends TestCase
{
    use FakesPublishChannel;
    use RefreshDatabase;

    /**
     * Events the capturing transport intercepted, shared with the
     * anonymous transport through this test instance (object handle
     * semantics — reference sharing without a second class in the file).
     *
     * @var list<Event>
     */
    private array $capturedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePublishChannel();
    }

    protected function tearDown(): void
    {
        // These tests swap the SDK's current hub for an isolated one. A
        // fresh, client-less hub is inert exactly like the app's own
        // unset-DSN hub, and the next app boot re-registers its own hub
        // (sentry-laravel's ServiceProvider calls SentrySdk::setCurrentHub
        // when HubInterface is resolved).
        SentrySdk::setCurrentHub(new Hub);

        parent::tearDown();
    }

    private function createPost(Workspace $workspace): Post
    {
        return Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Caption'],
        ]);
    }

    /**
     * An isolated hub whose transport captures every event the SDK would
     * hand to the network.
     */
    private function isolateSentryWithCapturingTransport(?string $dsn): Hub
    {
        $this->capturedEvents = [];

        $transport = new class($this) implements TransportInterface
        {
            public function __construct(private readonly PublishErrorContextTest $host) {}

            public function send(Event $event): Result
            {
                $this->host->acceptCapturedEvent($event);

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::skipped());
            }
        };

        $clientBuilder = ClientBuilder::create(['dsn' => $dsn]);
        $clientBuilder->setTransport($transport);
        $hub = new Hub($clientBuilder->getClient());
        SentrySdk::setCurrentHub($hub);

        return $hub;
    }

    /**
     * Capture seam for the anonymous transport above (public so the
     * nested class can reach it; not part of any test contract).
     */
    public function acceptCapturedEvent(Event $event): void
    {
        $this->capturedEvents[] = $event;
    }

    public function test_the_container_binds_the_sentry_implementation(): void
    {
        $this->assertInstanceOf(
            SentryPublishErrorContext::class,
            $this->app->make(PublishErrorContext::class),
        );
    }

    public function test_recorder_passes_the_identical_event_to_the_error_context(): void
    {
        $spy = new class implements PublishErrorContext
        {
            public ?PublishEventData $attached = null;

            public int $calls = 0;

            public function attach(PublishEventData $event): void
            {
                $this->calls++;
                $this->attached = $event;
            }
        };

        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);
        $event = PublishEventData::claim(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            jobId: 'job-ctx-1',
            operationId: 'op-ctx-1',
            runToken: 'run-ctx-1',
        );

        (new PublishEventRecorder($spy))->record($event);

        $this->assertSame(1, $spy->calls);
        $this->assertSame($event, $spy->attached);
        $this->assertSame(1, PublishEvent::query()->count());
    }

    public function test_a_throwing_error_context_never_reaches_the_publish_path(): void
    {
        $spy = new class implements PublishErrorContext
        {
            public int $calls = 0;

            public function attach(PublishEventData $event): void
            {
                $this->calls++;
                throw new RuntimeException('attach exploded');
            }
        };

        $workspace = Workspace::factory()->create();
        $post = $this->createPost($workspace);
        $event = PublishEventData::checkpoint(
            workspaceId: $workspace->id,
            publishableType: Post::class,
            publishableId: $post->getKey(),
            groupKey: 'group-ctx-1',
        );

        (new PublishEventRecorder($spy))->record($event);

        // No exception surfaced (record() returned normally) and both the
        // log line and the best-effort DB row still landed: an attach()
        // failure is swallowed exactly like the DB write (D-07 discipline).
        $this->assertSame(1, $spy->calls);
        $this->assertSame(1, PublishEvent::query()->count());
        $records = $this->publishEventRecords();
        $this->assertSame(1, count($records));
        $this->assertSame('group-ctx-1', $records[0]->context['group_key']);
    }

    public function test_attach_is_inert_and_emits_nothing_without_a_dsn(): void
    {
        // The app's inert state: no DSN configured. The transport is
        // swapped ONLY to count emits — attach() itself is pure in-process
        // scope mutation and must never capture; the SDK's real
        // HttpTransport additionally skips every send while the DSN is
        // null (its send() guard logs "Skipping …, because no DSN is
        // set"), so nothing leaves the process either.
        $this->isolateSentryWithCapturingTransport(null);

        $event = PublishEventData::providerHttp(
            workspaceId: 1,
            provider: 'postsyncer',
            status: 429,
            waitedSeconds: 30,
            path: '/api/publish',
        );

        (new SentryPublishErrorContext)->attach($event);

        $this->assertSame([], $this->capturedEvents);
    }

    public function test_attach_sets_the_exact_d10_tags_and_publish_context(): void
    {
        $hub = $this->isolateSentryWithCapturingTransport('http://public@sentry-local.invalid/1');

        $event = PublishEventData::claim(
            workspaceId: 7,
            publishableType: Post::class,
            publishableId: 42,
            jobId: 'job-1',
            operationId: 'op-1',
            runToken: 'run-1',
        );

        (new SentryPublishErrorContext)->attach($event);
        $probeId = $hub->captureMessage('publish-context-probe');

        $this->assertNotNull($probeId);
        $this->assertCount(1, $this->capturedEvents);

        $sentryEvent = $this->capturedEvents[0];
        $this->assertSame(['workspace_id' => '7', 'publish_stage' => 'claim'], $sentryEvent->getTags());

        $publish = $sentryEvent->getContexts()['publish'] ?? null;
        $this->assertIsArray($publish);
        $this->assertSame([
            'workspace_id' => 7,
            'content_id' => 42,
            'content_type' => Post::class,
            'job_id' => 'job-1',
            'operation_id' => 'op-1',
            'run_token' => 'run-1',
        ], $publish);
    }

    public function test_null_correlation_ids_are_omitted_from_the_publish_context(): void
    {
        $hub = $this->isolateSentryWithCapturingTransport('http://public@sentry-local.invalid/1');

        // Provider HTTP events observe no content item and no run
        // correlation — every nullable id is null here, so the publish
        // context must carry nothing but workspace_id.
        $event = PublishEventData::providerHttp(
            workspaceId: 3,
            provider: 'postsyncer',
            status: 429,
            waitedSeconds: 30,
            path: '/api/publish',
        );

        (new SentryPublishErrorContext)->attach($event);
        $probeId = $hub->captureMessage('publish-context-probe');

        $this->assertNotNull($probeId);
        $this->assertCount(1, $this->capturedEvents);

        $sentryEvent = $this->capturedEvents[0];
        $publish = $sentryEvent->getContexts()['publish'] ?? null;
        $this->assertIsArray($publish);
        $this->assertSame(['workspace_id' => 3], $publish);
    }
}
