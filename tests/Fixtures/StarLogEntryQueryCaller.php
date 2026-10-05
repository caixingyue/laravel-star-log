<?php

namespace App\StarLogTestFixtures;

use Illuminate\Support\Facades\DB;

final class StarLogEntryQueryCaller
{
    public function run(): array
    {
        return DB::select('select * from star_log_query_entries');
    }
}
