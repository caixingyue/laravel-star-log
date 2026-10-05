<?php

namespace Caixingyue\LaravelStarLog\Tests\Correlation;

use Caixingyue\LaravelStarLog\Correlation\CorrelationContext;
use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class CorrelationContextTest extends TestCase
{
    public function test_queue_payload_creation_keeps_the_dispatching_context_unchanged(): void
    {
        $context = new CorrelationContext($this->generator());
        $context->startRequestCorrelation(Request::create('/'));
        $context->appendArtisanCorrelation('App\\Console\\Commands\\SyncUsers');
        $before = $context->getCorrelationChain();

        $payload = $context->createQueueCorrelationChain('App\\Jobs\\SyncUsers');

        $this->assertSame($before, $context->getCorrelationChain());
        $this->assertSame($before, array_slice($payload, 0, 2));
        $this->assertSame('queue', $payload[2]['type']);
        $this->assertNotContains($payload[2]['id'], array_column($before, 'id'));
    }

    public function test_ids_already_present_in_an_imported_chain_are_skipped(): void
    {
        $collision = $this->generator()->generate();
        $context = new CorrelationContext($this->generator());
        $context->setCorrelationChain([
            ['id' => $collision, 'name' => Request::class, 'type' => 'request'],
        ]);

        $this->assertNotSame($collision, $context->generateCorrelationId());
        $this->assertSame($collision, $context->getRequestId());
    }

    private function generator(): DailyIdGenerator
    {
        return new DailyIdGenerator(
            new Repository(new ArrayStore),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-23 10:20:12'),
        );
    }
}
