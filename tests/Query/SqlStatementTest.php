<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

use Caixingyue\LaravelStarLog\Query\SqlStatement;
use PHPUnit\Framework\TestCase;

final class SqlStatementTest extends TestCase
{
    public function test_identifier_normalization_removes_quoting_and_normalizes_case(): void
    {
        $this->assertSame('tenant.users', SqlStatement::normalizeIdentifier('  `tenant` . [Users] '));
        $this->assertSame('tenant.users', SqlStatement::normalizeIdentifier('"tenant"."users"'));
        $this->assertSame('äusers', SqlStatement::normalizeIdentifier('"ÄUsers"'));
        $this->assertSame('0', SqlStatement::normalizeIdentifier('"0"'));
        $this->assertSame('', SqlStatement::normalizeIdentifier(' . '));
    }

    public function test_column_normalization_uses_the_final_qualified_identifier_segment(): void
    {
        $this->assertSame('password', SqlStatement::normalizeColumn('`users`.`password`'));
        $this->assertSame('password', SqlStatement::normalizeColumn('[users].[password]'));
        $this->assertSame('password', SqlStatement::normalizeColumn('password'));
    }

    public function test_primary_tables_match_unqualified_and_qualified_names_at_identifier_boundaries(): void
    {
        $statement = new SqlStatement('', '', 'tenant.users');

        $this->assertTrue($statement->targetsPrimaryTable('users'));
        $this->assertTrue($statement->targetsPrimaryTable('tenant.users'));
        $this->assertFalse($statement->targetsPrimaryTable('other.users'));
        $this->assertFalse($statement->targetsPrimaryTable('ser'));
        $this->assertFalse($statement->targetsPrimaryTable(''));
    }

    public function test_statements_without_a_primary_table_do_not_match_table_rules(): void
    {
        $statement = new SqlStatement('', '', null);

        $this->assertFalse($statement->targetsPrimaryTable('users'));
    }
}
