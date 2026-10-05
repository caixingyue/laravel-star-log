<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class ParentCorrelatedCommand extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'star-log:parent-correlation-test';

    public static array $executions = [];

    public static bool $dispatchQueueAfterChild = false;

    public static bool $callUnmarkedCommand = false;

    public function handle(): int
    {
        self::$executions[] = ['id' => $this->getId(), 'chain' => $this->getCorrelationChain()];

        Artisan::call(self::$callUnmarkedCommand
            ? 'star-log:unmarked-test'
            : 'star-log:child-correlation-test');

        if (self::$dispatchQueueAfterChild) {
            dispatch(new CommandDispatchedQueueJob);
        }

        self::$executions[] = ['id' => $this->getId(), 'chain' => $this->getCorrelationChain()];

        return self::SUCCESS;
    }
}
