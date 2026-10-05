<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class InspectableQueueJob implements ShouldBeCorrelated, ShouldQueue
{
    use InteractsWithCorrelation, Queueable;

    public static array $executions = [];

    public function handle(): void
    {
        self::$executions[] = [
            'id' => $this->getId(),
            'correlationId' => $this->getCurrentCorrelationId(),
            'queueId' => $this->getQueueId(),
            'artisanId' => $this->getArtisanId(),
            'nearestArtisanId' => $this->getNearestArtisanId(),
            'parent' => $this->getParentCorrelation(),
            'chain' => $this->getCorrelationChain(),
        ];
    }
}
