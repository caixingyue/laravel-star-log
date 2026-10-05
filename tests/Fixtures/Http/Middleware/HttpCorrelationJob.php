<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Http\Middleware;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class HttpCorrelationJob implements ShouldBeCorrelated, ShouldQueue
{
    use InteractsWithCorrelation, Queueable;

    public static array $executions = [];

    public function handle(): void
    {
        self::$executions[] = [
            'id' => $this->getId(),
            'requestId' => $this->getRequestId(),
            'artisanId' => $this->getArtisanId(),
            'queueId' => $this->getQueueId(),
            'chain' => $this->getCorrelationChain(),
        ];
    }
}
