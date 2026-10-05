<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;

final class CommandDispatchedQueueJob implements ShouldBeCorrelated, ShouldQueue
{
    use InteractsWithCorrelation, Queueable;

    public static array $executions = [];

    public static bool $callArtisan = false;

    public function handle(): void
    {
        self::$executions[] = [
            'id' => $this->getId(),
            'queueId' => $this->getQueueId(),
            'artisanId' => $this->getArtisanId(),
            'nearestArtisanId' => $this->getNearestArtisanId(),
            'parent' => StarLog::getParentCorrelation(),
            'chain' => StarLog::getCorrelationChain(),
        ];

        if (self::$callArtisan) {
            Artisan::call('star-log:queue-invoked-command');
        }
    }
}
