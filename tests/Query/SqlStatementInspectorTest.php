<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

use Caixingyue\LaravelStarLog\Query\SqlStatementInspector;
use PHPUnit\Framework\TestCase;

final class SqlStatementInspectorTest extends TestCase
{
    public function test_common_read_and_write_statements_expose_their_primary_table(): void
    {
        $inspector = new SqlStatementInspector;

        $this->assertSame('users', $inspector->inspect('select * from `users` where id = ?')->table);
        $this->assertSame('users', $inspector->inspect('select count(*) from users')->table);
        $this->assertSame('users', $inspector->inspect('insert into users (email) values (?)')->table);
        $this->assertSame('users', $inspector->inspect('insert ignore into users (email) values (?)')->table);
        $this->assertSame('users', $inspector->inspect('insert or ignore into users (email) values (?)')->table);
        $this->assertSame('users', $inspector->inspect('replace into users (email) values (?)')->table);
        $this->assertSame('users', $inspector->inspect('update users set name = ? where id = ?')->table);
        $this->assertSame('users', $inspector->inspect('delete from users where id = ?')->table);
        $this->assertSame('tenant.users', $inspector->inspect('select * from tenant.users')->table);
        $this->assertSame('tenant.users', $inspector->inspect('select * from "tenant"."users"')->table);
        $this->assertSame('0', $inspector->inspect('select * from "0"')->table);
    }

    public function test_joined_tables_do_not_replace_the_primary_table(): void
    {
        $statement = (new SqlStatementInspector)->inspect('select * from orders join users on users.id = orders.user_id');

        $this->assertSame('orders', $statement->table);
        $this->assertTrue($statement->targetsPrimaryTable('orders'));
        $this->assertFalse($statement->targetsPrimaryTable('users'));
    }

    public function test_table_extraction_ignores_sql_literals_and_comments(): void
    {
        $inspector = new SqlStatementInspector;

        $this->assertSame('users', $inspector->inspect("select 'x from hidden' as value from users")->table);
        $this->assertSame('users', $inspector->inspect('select /* x from hidden */ * from users')->table);
        $this->assertSame('users', $inspector->inspect('select "x from hidden" as value from users')->table);
        $this->assertSame('users', $inspector->inspect("select * from users -- x from hidden\nwhere id = ?")->table);
        $this->assertSame('users', $inspector->inspect("select * from users # x from hidden\nwhere id = ?")->table);
    }

    public function test_postgresql_only_table_modifiers_do_not_change_the_primary_table(): void
    {
        $inspector = new SqlStatementInspector;

        $this->assertSame('users', $inspector->inspect('select * from only users')->table);
        $this->assertSame('users', $inspector->inspect('update only users set name = ?')->table);
        $this->assertSame('users', $inspector->inspect('delete from only users')->table);
    }

    public function test_ambiguous_subqueries_remain_recordable_without_a_primary_table(): void
    {
        $inspector = new SqlStatementInspector;

        $statement = $inspector->inspect('with active_users as (select * from users) select * from active_users');

        $this->assertNull($statement->table);
        $this->assertSame([], $statement->bindingColumns);
        $this->assertNull($inspector->inspect("with\nactive_users as (select * from users) select * from active_users")->table);
        $this->assertNull($inspector->inspect('select * from (select * from users) as filtered_users')->table);
    }

    public function test_quoted_identifiers_containing_periods_do_not_match_table_rules(): void
    {
        $statement = (new SqlStatementInspector)->inspect('select * from "audit.logs"');

        $this->assertNull($statement->table);
        $this->assertSame([], $statement->bindingColumns);
    }

    public function test_safe_insert_and_update_shapes_map_positional_bindings_to_columns(): void
    {
        $inspector = new SqlStatementInspector;

        $this->assertSame(
            ['email', 'password'],
            $inspector->inspect('insert into users (email, password) values (?, ?)')->bindingColumns
        );

        $this->assertSame(
            ['email', 'password'],
            $inspector->inspect('insert or ignore into users (email, password) values (?, ?)')->bindingColumns
        );

        $this->assertSame(
            ['email', 'password'],
            $inspector->inspect('replace into users (email, password) values (?, ?)')->bindingColumns
        );

        $this->assertSame(
            ['name', 'password', 'id'],
            $inspector->inspect('update users set name = ?, password = ? where id = ?')->bindingColumns
        );

        $this->assertSame(
            ['password', 'id'],
            $inspector->inspect('update users set users.password = ? where id = ?')->bindingColumns
        );

        $this->assertSame(
            ['email', 'password', 'email', 'password'],
            $inspector->inspect('insert into users (email, password) values (?, ?), (?, ?)')->bindingColumns
        );

        $this->assertSame(
            [],
            $inspector->inspect('insert into users (email, password) values (?, ?), (?, lower(?))')->bindingColumns
        );

        $this->assertSame(
            ['name', 'password', 'id'],
            $inspector->inspect('update only users set name = ?, password = ? where id = ?')->bindingColumns
        );

        $this->assertSame(
            [],
            $inspector->inspect('insert into users (email, password) values (?, ?) on duplicate key update password = ?')->bindingColumns
        );

        $this->assertSame(
            ['email', 'password'],
            $inspector->inspect('insert into users (email, password) values (?, ?) on conflict (email) do update set password = excluded.password')->bindingColumns
        );
    }

    public function test_sql_normalization_produces_a_stable_value_for_contains_rules(): void
    {
        $inspector = new SqlStatementInspector;

        $this->assertSame(
            'select * from users where email = ?',
            $inspector->normalizeSql("\n SELECT  * FROM `users`\nWHERE [email] = ? ")
        );
    }
}
