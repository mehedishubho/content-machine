<?php

namespace Tests\Unit\Support\Socialreins;

use App\Models\PublishEvent;
use App\Models\Workspace;
use App\Support\Observability\PublishEventRecorder;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPublishChannel;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

class SocialreinsClientInstrumentationTest extends TestCase
{
    use FakesPublicDns;
    use FakesPublishChannel;
    use RefreshDatabase;

    private const URL = 'https://brain.test/v1/post-groups';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveHostsTo();
        $this->fakePublishChannel();
    }

    private function instrumentedClient(Workspace $workspace): SocialreinsClient
    {
        SocialreinsConfig::write($workspace, [
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => '11111111-1111-4111-8111-111111111111', 'token' => 'sr_set_live_english'],
            ],
        ]);

        return new SocialreinsClient(
            SocialreinsConfig::fromWorkspace($workspace->refresh()),
            'english',
            new PublishEventRecorder,
            $workspace->id,
        );
    }

    public function test_a_5xx_response_emits_a_provider_http_event_with_the_actual_status(): void
    {
        $workspace = Workspace::factory()->create();
        $client = $this->instrumentedClient($workspace);
        Http::fake([
            self::URL => Http::response(['error' => 'internal'], 500),
        ]);

        try {
            $client->createGroup('key-1', '{"x":1}');
            $this->fail('Expected the 500 to throw.');
        } catch (SocialreinsException $exception) {
            $this->assertStringContainsString('SocialReins answered 500', $exception->getMessage());
        }

        $this->assertSame(['provider_http'], $this->publishEventStages());
        $event = $this->publishEventContexts()[0];
        $this->assertSame(500, $event['payload']['status']);
        $this->assertNull($event['payload']['waited_seconds']);
        $this->assertSame('/post-groups', $event['payload']['path']);
        $this->assertSame('socialreins', $event['provider']);
        $this->assertSame($workspace->id, $event['workspace_id']);
        $this->assertSame(1, PublishEvent::query()->count());
    }

    public function test_a_connection_timeout_emits_a_provider_http_event_with_status_zero(): void
    {
        $workspace = Workspace::factory()->create();
        $client = $this->instrumentedClient($workspace);
        Http::fake([
            'brain.test/*' => fn () => throw new ConnectionException('cURL error 28'),
        ]);

        try {
            $client->createGroup('key-1', '{"x":1}');
            $this->fail('Expected the connection failure to throw.');
        } catch (SocialreinsException $exception) {
            $this->assertStringContainsString('could not be reached', $exception->getMessage());
        }

        $this->assertSame(['provider_http'], $this->publishEventStages());
        $event = $this->publishEventContexts()[0];
        $this->assertSame(0, $event['payload']['status']);
        $this->assertNull($event['payload']['waited_seconds']);
        $this->assertSame(1, PublishEvent::query()->count());
    }

    public function test_a_client_without_a_recorder_emits_no_events(): void
    {
        $workspace = Workspace::factory()->create();
        SocialreinsConfig::write($workspace, [
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => '11111111-1111-4111-8111-111111111111', 'token' => 'sr_set_live_english'],
            ],
        ]);
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($workspace->refresh()), 'english');
        Http::fake([
            self::URL => Http::response(['error' => 'internal'], 500),
        ]);

        try {
            $client->createGroup('key-1', '{"x":1}');
            $this->fail('Expected the 500 to throw.');
        } catch (SocialreinsException) {
            // Behavior unchanged for unmodified call sites.
        }

        $this->assertSame([], $this->publishEventStages());
        $this->assertSame(0, PublishEvent::query()->count());
    }
}
