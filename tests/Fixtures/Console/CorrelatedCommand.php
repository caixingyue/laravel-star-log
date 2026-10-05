<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Console\Command;

final class CorrelatedCommand extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'star-log:correlation-test';

    public static array $executions = [];

    public function handle(): int
    {
        self::$executions[] = StarLog::getCorrelationChain();

        return self::SUCCESS;
    }
}
