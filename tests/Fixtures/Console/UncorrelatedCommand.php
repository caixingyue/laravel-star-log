<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class UncorrelatedCommand extends Command
{
    protected $signature = 'star-log:unmarked-test';

    public static array $executions = [];

    public function handle(): int
    {
        self::$executions[] = StarLog::getCorrelationChain();

        Artisan::call('star-log:child-correlation-test');

        self::$executions[] = StarLog::getCorrelationChain();

        return self::SUCCESS;
    }
}
