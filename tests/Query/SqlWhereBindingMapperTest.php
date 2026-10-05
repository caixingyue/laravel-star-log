<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use Caixingyue\LaravelStarLog\Query\QueryBindingFormatter;
use Caixingyue\LaravelStarLog\Query\SqlStatementInspector;
use Caixingyue\LaravelStarLog\Query\SqlWhereBindingMapper;
use PHPUnit\Framework\TestCase;

final class SqlWhereBindingMapperTest extends TestCase
{
    public function test_single_table_predicates_retain_public_values_and_mask_sensitive_columns(): void
    {
        $this->assertSame([123, '******'], $this->format('select * from users where id = ? and password = ?', [123, 'secret']));
        $this->assertSame([123], $this->format('select count(*) as aggregate from users where id = ? limit 1', [123]));
        $this->assertSame(['Taylor', '******'], $this->format('update users set name = ? where password = ?', ['Taylor', 'secret']));
        $this->assertSame([123, 456, '******'], $this->format('delete from users where id in (?, ?) and password = ?', [123, 456, 'secret']));
    }

    public function test_grouped_in_between_and_literal_predicates_preserve_binding_positions(): void
    {
        $this->assertSame([18, 65, 1, 2, '******'], $this->format(
            "select * from users where (age between ? and ? or id in (?, ?)) and status = 'active' and password = ? order by id desc limit 10 offset 2",
            [18, 65, 1, 2, 'secret'],
        ));
        $this->assertSame(['******'], $this->format(
            "select * from users where name = '? :fake -- comment /* password = ? */' and password = ?", ['secret'],
        ));
        $this->assertSame([123], $this->format('select * from users where deleted_at is null and id >= ?', [123]));
    }

    public function test_comments_do_not_shift_or_disable_verified_bindings(): void
    {
        $this->assertSame(['Taylor', '******'], $this->format(
            "/* prefix */ insert into users (name /* ? */, password -- note\n) values (?, ?)", ['Taylor', 'secret'],
        ));
        $this->assertSame([123, '******'], $this->format(
            'select * from users where id = ? /* password = ? */ and password = ?', [123, 'secret'],
        ));
    }

    public function test_named_placeholders_follow_verified_columns_and_not_their_names(): void
    {
        $this->assertSame(['public' => '******', ':number' => 123], $this->format(
            'select * from users where password = :public and id = :number', ['public' => 'secret', ':number' => 123],
        ));
        $this->assertSame([':public' => 'Taylor'], $this->format('select * from users where name = :public', [':public' => 'Taylor']));
        $this->assertSame(['shared' => 'secret'], $this->format('select * from users where id = :shared or password = :shared', ['shared' => 'secret']));
    }

    public function test_qualified_columns_must_belong_to_the_single_verified_table(): void
    {
        $this->assertSame([123, '******'], $this->format(
            'select * from tenant.users as u where u.id = ? and "u"."password" = ?', [123, 'secret'],
        ));
        $this->assertSame(['secret'], $this->format('select * from users where other.id = ?', ['secret']));
    }

    public function test_repeated_named_placeholders_remain_ambiguous_after_a_conflict(): void
    {
        $mapper = new SqlWhereBindingMapper;

        $this->assertSame(['shared' => 'id'], $mapper->map('id = :shared or users.id = :shared', ['users']));
        $this->assertSame(['shared' => null], $mapper->map('id = :shared or password = :shared or password = :shared', ['users']));
        $this->assertSame(['shared' => null], $mapper->map('other.id = :shared or id = :shared or id = :shared', ['users']));
    }

    public function test_literals_and_quoted_identifiers_do_not_create_bindings(): void
    {
        $mapper = new SqlWhereBindingMapper;

        $this->assertSame(['id'], $mapper->map(' (id = ?) ', ['users']));
        $this->assertSame(['age', 'id', 'password'], $mapper->map(
            "(age not between -1.5 and ? or [users].[id] not in (1, ?, null)) and name not like '? :fake' and active = true and deleted_at is not null and `users`.`password` != ?",
            ['users'],
        ));
    }

    public function test_incomplete_or_unsupported_predicates_are_not_mapped(): void
    {
        $mapper = new SqlWhereBindingMapper;

        foreach ([
            '', '   ', '()',
            'id = ? and', 'and id = ?', 'id = ? password = ?',
            '(id = ?', 'id = ?)', 'id in ()', 'id between ? and',
            'lower(password) = ?', 'id = ? and password = :secret',
        ] as $conditions) {
            $this->assertNull($mapper->map($conditions, ['users']), $conditions);
        }

        $this->assertNull($mapper->map(str_repeat(' ', 65536) . 'id = ?', ['users']));
    }

    public function test_ambiguous_queries_and_dialect_specific_comments_preserve_unmapped_values(): void
    {
        foreach ([
            'select * from users join accounts on accounts.id = users.id where accounts.password = ?',
            'select * from users where lower(password) = ?',
            'select * from users where id = ? or exists (select 1 from accounts)',
            'select * from users where id = ? union select * from accounts',
            'select * from users where id = ?; select * from accounts',
            'select (select 1 from accounts) from users where password = ?',
            'select * from users where id = ? /*! AND password = ? */',
            'select * from users where id = ? /*M! AND password = ? */',
            'select * from users where id = ? # dialect-specific text',
            'select * from users where id = ?--1',
            'select * from users where id = ? and password = :shared',
        ] as $sql) {
            $this->assertSame(['secret'], $this->format($sql, ['secret']), $sql);
        }
    }

    public function test_predicate_work_and_group_depth_are_bounded(): void
    {
        $inspector = new SqlStatementInspector;
        $this->assertSame([], $inspector->inspect('select * from users where ' . str_repeat('(', 65) . 'id = ?' . str_repeat(')', 65))->bindingColumns);
        $this->assertSame([], $inspector->inspect('select * from users where ' . implode(' and ', array_fill(0, 1001, 'id = ?')))->bindingColumns);
    }

    private function format(string $sql, array $bindings): array|string
    {
        return (new QueryBindingFormatter)->format($bindings, (new SqlStatementInspector)->inspect($sql), [
            'enable' => true, 'columns' => ['*' => ['password' => ['sensitive' => true]]],
        ], new QueryBindingColumnRegistry);
    }
}
