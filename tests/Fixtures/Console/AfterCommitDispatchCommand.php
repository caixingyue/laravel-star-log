<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\DispatchSnapshotQueueJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AfterCommitDispatchCommand extends Command implements ShouldBeCorrelated
{
    protected $signature = 'star-log:after-commit-dispatch';

    public static array $executions = [];

    public function handle(): int
    {
        self::$executions[] = StarLog::getCorrelationChain();

        DB::transaction(static function (): void {
            dispatch((new DispatchSnapshotQueueJob)->onConnection('sync')->afterCommit());
        });

        return self::SUCCESS;
    }
}
