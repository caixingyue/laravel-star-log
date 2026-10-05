<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use PHPUnit\Framework\TestCase;

final class QueryBindingColumnRegistryTest extends TestCase
{
    public function test_partial_column_settings_are_merged_for_the_same_table(): void
    {
        $registry = new QueryBindingColumnRegistry;

        $registry->register('users', ['password' => ['sensitive' => true]]);
        $registry->register('users', ['password' => ['max_length' => 12]]);

        $this->assertSame([
            'password' => ['sensitive' => true, 'max_length' => 12],
        ], $registry->forTable('users'));
    }

    public function test_table_names_are_normalized_and_empty_names_are_ignored(): void
    {
        $registry = new QueryBindingColumnRegistry;

        $registry->register(' `tenant` . [Users] ', ['password' => ['sensitive' => true]]);
        $registry->register(' . ', ['token' => ['sensitive' => true]]);

        $this->assertSame([
            'password' => ['sensitive' => true],
        ], $registry->forTable('tenant.users'));
        $this->assertSame([], $registry->forTable(null));
        $this->assertSame([], $registry->forTable(' . '));
    }
}
