<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Query;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class QueryLimitJob implements ShouldBeCorrelated, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly bool $nested = false) {}

    public function handle(): void
    {
        DB::select('select 1');

        if ($this->nested) {
            dispatch(new self);
        }

        DB::select('select 2');
    }
}
