<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Query;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class QueryLimitCommand extends Command implements ShouldBeCorrelated
{
    protected $signature = 'star-log:query-limit {--nested}';

    public function handle(): int
    {
        DB::select('select 1');

        if ($this->option('nested')) {
            Artisan::call('star-log:query-limit');
        }

        DB::select('select 2');

        return self::SUCCESS;
    }
}
