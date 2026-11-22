<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Intercepts the dedicated `publish` log channel for assertions. Laravel
 * ships no Log::fake() in this framework version, so the channel is routed
 * through a Monolog TestHandler: Log::spy() takes over the `log` binding and
 * channel('publish') is stubbed to a real Monolog Logger backed by
 * TestHandler, preserving emission order for stage-sequence assertions.
 */
trait FakesPublishChannel
{
    private TestHandler $publishChannelHandler;

    private MockInterface $logManagerSpy;

    protected function fakePublishChannel(): void
    {
        $this->publishChannelHandler = new TestHandler;
        $monolog = new Logger('publish', [$this->publishChannelHandler]);
        $this->logManagerSpy = Log::spy();
        $this->logManagerSpy->shouldReceive('channel')
            ->with('publish')
            ->andReturn($monolog);
        $this->instance('log', $this->logManagerSpy);
    }

    /**
     * Publish-event records in emission order.
     *
     * @return list<LogRecord>
     */
    private function publishEventRecords(): array
    {
        return array_values(array_filter(
            $this->publishChannelHandler->getRecords(),
            static fn (LogRecord $record): bool => $record->message === 'publish_event',
        ));
    }

    /**
     * Publish-event log contexts in emission order.
     *
     * @return list<array<string, mixed>>
     */
    private function publishEventContexts(): array
    {
        return array_map(
            static fn (LogRecord $record): array => $record->context,
            $this->publishEventRecords(),
        );
    }

    /**
     * Stages of every publish event emitted so far, in order.
     *
     * @return list<string>
     */
    private function publishEventStages(): array
    {
        return array_map(
            static fn (array $context): string => (string) $context['stage'],
            $this->publishEventContexts(),
        );
    }
}
