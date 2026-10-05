<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use Caixingyue\LaravelStarLog\Query\QueryBindingFormatter;
use Caixingyue\LaravelStarLog\Query\SqlStatementInspector;
use PHPUnit\Framework\TestCase;

final class QueryBindingFormatterTest extends TestCase
{
    public function test_commented_insert_columns_mask_secrets_and_retain_public_bindings(): void
    {
        foreach ([
            'insert into users (name, password /* note */) values (?, ?)',
            'insert into users (name, /* note */ password) values (?, ?)',
            "insert into users (name, password -- note\n) values (?, ?)",
        ] as $sql) {
            $this->assertSame(['Taylor', '******'], (new QueryBindingFormatter)->format(
                ['Taylor', 'synthetic-secret'], (new SqlStatementInspector)->inspect($sql),
                ['enable' => true, 'columns' => ['*' => ['password' => ['sensitive' => true]]]],
                new QueryBindingColumnRegistry,
            ));
        }
    }

    public function test_only_verified_sensitive_columns_are_masked(): void
    {
        $formatter = new QueryBindingFormatter;
        $inspector = new SqlStatementInspector;
        $registry = new QueryBindingColumnRegistry;

        foreach ([
            ['select * from users where password = ?', ['secret']],
            ['select * from users where password = :public_name', [':public_name' => 'secret']],
        ] as [$sql, $bindings]) {
            $output = $formatter->format($bindings, $inspector->inspect($sql), [
                'enable' => true, 'columns' => ['*' => ['password' => ['sensitive' => true]]],
            ], $registry);

            $this->assertStringNotContainsString('secret', json_encode($output));
            $this->assertContains('******', $output);
        }

        $this->assertSame(['visible', '******'], $formatter->format(
            ['visible', 'secret'], $inspector->inspect('update users set name = ? where password = ?'),
            ['enable' => true, 'columns' => ['*' => ['password' => ['sensitive' => true]]]], $registry,
        ));
    }

    public function test_unmapped_bindings_and_placeholder_names_do_not_trigger_masking(): void
    {
        foreach ([
            ['with u as (select * from users where password = ?) select * from u', ['visible']],
            ['insert into users (password) values (lower(?))', ['visible']],
            ['select * from users where lower(name) = :password', [':password' => 'visible']],
            ['select * from users where name = :password', [':password' => 'visible']],
        ] as [$sql, $bindings]) {
            $this->assertSame($bindings, (new QueryBindingFormatter)->format(
                $bindings, (new SqlStatementInspector)->inspect($sql),
                ['enable' => true, 'columns' => ['*' => ['password' => ['sensitive' => true]]]],
                new QueryBindingColumnRegistry,
            ));
        }

        $formatted = (new QueryBindingFormatter)->format(
            [':password' => 'secret-value'],
            (new SqlStatementInspector)->inspect('update users set password = :password'),
            [
                'enable' => true,
                'columns' => [
                    'users' => [
                        'password' => ['sensitive' => true],
                    ],
                ],
            ],
            new QueryBindingColumnRegistry
        );

        $this->assertSame([':password' => 'secret-value'], $formatted);
    }

    public function test_verified_columns_remain_visible_when_column_settings_do_not_require_masking(): void
    {
        $statement = (new SqlStatementInspector)->inspect('select * from users where password = ?');
        $formatter = new QueryBindingFormatter;
        $this->assertSame(['visible'], $formatter->format(['visible'], $statement, ['enable' => true], new QueryBindingColumnRegistry));
        $this->assertSame(['visible'], $formatter->format(['visible'], $statement, [
            'enable' => true,
            'columns' => ['*' => ['password' => ['sensitive' => true]], 'users' => ['password' => ['sensitive' => false]]],
        ], new QueryBindingColumnRegistry));
    }

    public function test_cyclic_binding_arrays_are_bounded(): void
    {
        $value = [];
        $value['self'] = &$value;

        $output = (new QueryBindingFormatter)->format(
            [$value], (new SqlStatementInspector)->inspect('insert into users (payload) values (?)'),
            ['enable' => true], new QueryBindingColumnRegistry,
        );

        $this->assertStringContainsString('[maximum depth reached]', json_encode($output));
    }

    public function test_bindings_are_hidden_until_explicitly_enabled(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            ['secret'],
            (new SqlStatementInspector)->inspect('select * from users where password = ?'),
            [],
            new QueryBindingColumnRegistry
        );

        $this->assertSame('[hidden]', $formatted);
    }

    public function test_column_settings_mask_sensitive_values_and_override_default_length_limits(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            ['user@example.com', 'secret-value', 'abcdef'],
            (new SqlStatementInspector)->inspect('insert into users (email, password, profile) values (?, ?, ?)'),
            [
                'enable' => true,
                'max_length' => 10,
                'columns' => [
                    '*' => [
                        'password' => ['sensitive' => true],
                    ],
                    'users' => [
                        'profile' => ['max_length' => 3],
                    ],
                ],
            ],
            new QueryBindingColumnRegistry
        );

        $this->assertSame([
            'user@examp…',
            '******',
            'abc…',
        ], $formatted);
    }

    public function test_model_column_settings_override_configured_table_settings(): void
    {
        $registry = new QueryBindingColumnRegistry;
        $registry->register('users', [
            'email' => ['sensitive' => true],
        ]);

        $formatted = (new QueryBindingFormatter)->format(
            ['user@example.com'],
            (new SqlStatementInspector)->inspect('insert into users (email) values (?)'),
            [
                'enable' => true,
                'columns' => [
                    'users' => [
                        'email' => ['max_length' => 5],
                    ],
                ],
            ],
            $registry
        );

        $this->assertSame(['******'], $formatted);
    }

    public function test_column_settings_merge_by_precedence_level(): void
    {
        $registry = new QueryBindingColumnRegistry;
        $registry->register('users', [
            'model_secret' => ['max_length' => 3],
        ]);

        $formatted = (new QueryBindingFormatter)->format(
            ['global-secret', 'table-secret', 'model-secret'],
            (new SqlStatementInspector)->inspect(
                'insert into users (global_secret, table_secret, model_secret) values (?, ?, ?)'
            ),
            [
                'enable' => true,
                'columns' => [
                    '*' => [
                        'global_secret' => ['sensitive' => true],
                        'table_secret' => ['max_length' => 3],
                        'model_secret' => ['sensitive' => true],
                    ],
                    'users' => [
                        'global_secret' => ['max_length' => 3],
                        'table_secret' => ['sensitive' => true],
                    ],
                ],
            ],
            $registry
        );

        $this->assertSame(['******', '******', '******'], $formatted);
    }

    public function test_column_settings_apply_to_each_row_of_a_multi_row_insert(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            ['first@example.com', 'first-secret', 'second@example.com', 'second-secret'],
            (new SqlStatementInspector)->inspect('insert into users (email, password) values (?, ?), (?, ?)'),
            [
                'enable' => true,
                'columns' => [
                    '*' => [
                        'password' => ['sensitive' => true],
                    ],
                ],
            ],
            new QueryBindingColumnRegistry
        );

        $this->assertSame([
            'first@example.com',
            '******',
            'second@example.com',
            '******',
        ], $formatted);
    }

    public function test_binding_count_limits_add_an_omission_marker_without_coercing_strings(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            ['123', 'true', 'ignored'],
            (new SqlStatementInspector)->inspect('insert into users (id, active, omitted) values (?, ?, ?)'),
            [
                'enable' => true,
                'max_count' => 2,
            ],
            new QueryBindingColumnRegistry
        );

        $this->assertSame([
            '123',
            'true',
            '…' => '1 binding(s) omitted',
        ], $formatted);
    }

    public function test_malformed_column_settings_fall_back_to_the_default_length_limit(): void
    {
        $formatter = new QueryBindingFormatter;
        $statement = (new SqlStatementInspector)->inspect('insert into users (profile) values (?)');

        $this->assertSame(['abc…'], $formatter->format(
            ['abcdef'],
            $statement,
            ['enable' => true, 'max_length' => 3, 'columns' => 'invalid'],
            new QueryBindingColumnRegistry
        ));

        $this->assertSame(['abc…'], $formatter->format(
            ['abcdef'],
            $statement,
            [
                'enable' => true,
                'max_length' => 3,
                'columns' => ['users' => ['profile' => ['max_length' => 'invalid']]],
            ],
            new QueryBindingColumnRegistry
        ));
    }

    public function test_null_column_length_limit_disables_the_default_truncation(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            ['abcdef'],
            (new SqlStatementInspector)->inspect('insert into users (profile) values (?)'),
            [
                'enable' => true,
                'max_length' => 3,
                'columns' => ['users' => ['profile' => ['max_length' => null]]],
            ],
            new QueryBindingColumnRegistry
        );

        $this->assertSame(['abcdef'], $formatted);
    }

    public function test_binary_bindings_are_replaced_with_their_size(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            ["\x00\xFF\x10"],
            (new SqlStatementInspector)->inspect('insert into users (payload) values (?)'),
            ['enable' => true],
            new QueryBindingColumnRegistry
        );

        $this->assertSame(['[binary: 3 bytes]'], $formatted);
    }

    public function test_binary_stringable_bindings_are_replaced_with_their_size(): void
    {
        $formatted = (new QueryBindingFormatter)->format(
            [new class implements \Stringable
            {
                public function __toString(): string
                {
                    return "\x00\xFF\x10";
                }
            }],
            (new SqlStatementInspector)->inspect('insert into users (payload) values (?)'),
            ['enable' => true],
            new QueryBindingColumnRegistry
        );

        $this->assertSame(['[binary: 3 bytes]'], $formatted);
    }
}
