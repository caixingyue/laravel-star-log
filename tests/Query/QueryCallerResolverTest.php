<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

require_once __DIR__ . '/../Fixtures/QueryCallerProbe.php';

use App\StarLogTestFixtures\QueryCallerProbe;
use PHPUnit\Framework\TestCase;

final class QueryCallerResolverTest extends TestCase
{
    public function test_resolves_the_closest_application_class_outside_framework_infrastructure(): void
    {
        $this->assertSame(QueryCallerProbe::class, (new QueryCallerProbe)->resolve());
    }
}
