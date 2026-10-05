<?php

namespace Caixingyue\LaravelStarLog\Tests\Correlation;

use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class CorrelationChainTest extends TestCase
{
    public function test_request_artisan_and_queue_form_a_complete_chain(): void
    {
        $request = Request::create('/');
        $starLog = new StarLog($request, [], $this->generator(), new QueryLogState);

        $requestId = $starLog->startRequestCorrelation();
        $artisanId = $starLog->appendArtisanCorrelation('App\\Console\\Commands\\SyncUsers');
        $queueChain = $starLog->createQueueCorrelationChain('App\\Jobs\\SyncUsers');
        $starLog->setCorrelationChain($queueChain);

        $this->assertSame([
            $this->correlation($requestId, Request::class, 'request'),
            $this->correlation($artisanId, 'App\\Console\\Commands\\SyncUsers', 'artisan'),
            $this->correlation($queueChain[2]['id'], 'App\\Jobs\\SyncUsers', 'queue'),
        ], $starLog->getCorrelationChain());
        $this->assertSame([$queueChain[0], $queueChain[2]], $starLog->getDisplayCorrelations());
        $this->assertNull($request->attributes->get('requestId'));
    }

    public function test_type_ids_prefer_the_current_item_and_fall_back_to_the_nearest_parent(): void
    {
        $starLog = $this->starLog();
        $request = $this->correlation(101, Request::class, 'request');
        $artisan = $this->correlation(102, 'App\\Console\\Commands\\SyncUsers', 'artisan');
        $queue = $this->correlation(103, 'App\\Jobs\\SyncUsers', 'queue');

        $starLog->setCorrelationChain([$request, $artisan, $queue]);

        $this->assertSame(101, $starLog->getRequestId());
        $this->assertSame(102, $starLog->getArtisanId());
        $this->assertSame(103, $starLog->getQueueId());
        $this->assertSame(102, $starLog->getNearestArtisanId());
        $this->assertNull($starLog->getNearestQueueId());
        $this->assertSame($artisan, $starLog->getParentCorrelation());

        $starLog->setCorrelationChain([$queue, $artisan]);

        $this->assertSame(102, $starLog->getArtisanId());
        $this->assertSame(103, $starLog->getQueueId());
        $this->assertNull($starLog->getNearestArtisanId());
        $this->assertSame(103, $starLog->getNearestQueueId());
        $this->assertSame($queue, $starLog->getParentCorrelation());
    }

    public function test_starting_a_request_discards_the_previous_correlation_chain(): void
    {
        $starLog = $this->starLog();
        $starLog->setCorrelationChain([
            $this->correlation(101, Request::class, 'request'),
            $this->correlation(102, 'App\\Jobs\\SyncUsers', 'queue'),
        ]);

        $requestId = $starLog->startRequestCorrelation();

        $this->assertSame([
            $this->correlation($requestId, Request::class, 'request'),
        ], $starLog->getCorrelationChain());
    }

    public function test_correlation_state_belongs_to_the_star_log_instance_not_the_request(): void
    {
        $request = Request::create('/');
        $first = new StarLog($request, [], $this->generator(), new QueryLogState);
        $second = new StarLog($request, [], $this->generator(), new QueryLogState);
        $chain = [$this->correlation(101, Request::class, 'request')];

        $first->setCorrelationChain($chain);

        $this->assertSame($chain, $first->getCorrelationChain());
        $this->assertSame([], $second->getCorrelationChain());
        $this->assertNull($request->attributes->get('starLogCorrelationChain'));
    }

    public function test_invalid_correlation_items_are_removed_when_a_chain_is_set(): void
    {
        $starLog = $this->starLog();
        $valid = $this->correlation(101, Request::class, 'request');

        $starLog->setCorrelationChain([
            $valid,
            ['id' => '102', 'name' => 'App\\Jobs\\Invalid', 'type' => 'queue'],
            ['id' => 103, 'name' => '', 'type' => 'queue'],
            ['id' => 104, 'name' => 'App\\Jobs\\Invalid', 'type' => 'invalid'],
        ]);

        $this->assertSame([$valid], $starLog->getCorrelationChain());
    }

    private function starLog(): StarLog
    {
        return new StarLog(Request::create('/'), [], $this->generator(), new QueryLogState);
    }

    private function generator(): DailyIdGenerator
    {
        return new DailyIdGenerator(
            new Repository(new ArrayStore),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-23 10:20:12')
        );
    }

    private function correlation(int $id, string $name, string $type): array
    {
        return compact('id', 'name', 'type');
    }
}
