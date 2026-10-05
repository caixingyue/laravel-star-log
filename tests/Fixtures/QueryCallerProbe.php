<?php

namespace App\StarLogTestFixtures;

use Caixingyue\LaravelStarLog\Query\QueryCallerResolver;

final class QueryCallerProbe
{
    public function resolve(): ?string
    {
        return (new QueryCallerResolver)->resolveClassName();
    }
}
