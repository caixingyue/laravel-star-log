<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Query;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\StarLog as StarLogService;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Concerns\SqlLoggingColumnModel;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class QueryLogScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('starlog.query.enable', false);
        DB::statement('create table sql_logging_column_models (id integer, phone text, password text)');
        DB::insert('insert into sql_logging_column_models values (?, ?, ?)', [1, 'private phone', 'private password']);
        config()->set('starlog.query.bindings.enable', true);
        config()->set('starlog.query.bindings.columns', ['*' => ['password' => ['sensitive' => false]]]);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogService::class);
        Log::spy();
    }

    public function test_enabling_logging_returns_business_results_and_restores_the_disabled_default(): void
    {
        $rows = StarLog::withQueryLogging(fn () => DB::select('select * from sql_logging_column_models where id = ?', [1]));
        $this->assertCount(1, $rows);
        $this->assertFalse(StarLog::getConfig('query.enable'));
        DB::select('select * from sql_logging_column_models where id = ?', [1]);
        Log::shouldHaveReceived('info')->once();
    }

    public function test_outer_pauses_and_table_ignores_remain_effective_inside_enable_scopes(): void
    {
        StarLog::withoutQueryLogging(fn () => StarLog::withQueryLogging(fn () => DB::select('select 1')));
        StarLog::withoutQueryLoggingForTables(['sql_logging_column_models'], fn () => StarLog::withQueryLogging(
            fn () => DB::select('select * from sql_logging_column_models'),
        ));
        Log::shouldNotHaveReceived('info');
        StarLog::withQueryLogging(fn () => DB::select('select * from sql_logging_column_models'));
        Log::shouldHaveReceived('info')->once();
    }

    public function test_sensitive_column_rules_override_model_settings_and_restore_after_nested_scopes(): void
    {
        StarLog::withQueryLogging(function (): void {
            $query = static fn () => SqlLoggingColumnModel::where('password', 'private password')->first();
            $this->assertNotNull($query());
            StarLog::withoutQuerySensitiveColumns(['sql_logging_column_models' => ['password']], function () use ($query): void {
                $this->assertNotNull($query());
                StarLog::withQuerySensitiveColumns(['*' => ['password']], $query);
                $query();
            });
            $query();
        });

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['******'])->times(3);
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['private password'])->twice();
    }

    public function test_adding_sensitive_columns_does_not_change_queries_and_unmapped_bindings_stay_visible(): void
    {
        StarLog::withQueryLogging(function (): void {
            StarLog::withQuerySensitiveColumns(['*' => ['phone']], function (): void {
                $rows = DB::select('select * from sql_logging_column_models where phone = ?', ['private phone']);
                $this->assertCount(1, $rows);
                DB::select('select * from sql_logging_column_models where lower(phone) = ?', ['private phone']);
            });
            DB::select('select * from sql_logging_column_models where phone = ?', ['private phone']);
        });
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['******'])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['private phone'])->twice();
    }

    public function test_canceling_sensitive_columns_does_not_enable_hidden_bindings(): void
    {
        StarLog::withQueryLogOptions(['enable' => true, 'bindings' => ['enable' => false]], fn () => StarLog::withoutQuerySensitiveColumns(['*' => ['password']], fn () => SqlLoggingColumnModel::where('password', 'private password')->first()));
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === '[hidden]')->once();
        $this->assertTrue(StarLog::getConfig('query.bindings.enable'));
    }

    public function test_unqualified_table_rules_match_schema_qualified_queries_and_specific_tables_override_defaults(): void
    {
        DB::statement("attach database ':memory:' as tenant");
        DB::statement('create table tenant.accounts (password text)');
        DB::insert('insert into tenant.accounts (password) values (?)', ['private']);
        StarLog::withQueryLogOptions([
            'enable' => true,
            'bindings' => ['columns' => ['*' => ['password' => ['sensitive' => true]]]],
        ], fn () => StarLog::withoutQuerySensitiveColumns(
            ['accounts' => ['password']],
            function (): void {
                $this->assertCount(1, DB::select('select * from tenant.accounts where password = ?', ['private']));
                StarLog::withQuerySensitiveColumns(['tenant.accounts' => ['password']], fn () => DB::select('select * from tenant.accounts where password = ?', ['private']));
            },
        ));
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['private'])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['******'])->once();
    }

    public function test_options_and_masks_restore_after_a_business_exception(): void
    {
        try {
            StarLog::withQueryLogOptions(['enable' => true, 'bindings' => ['max_length' => 3]], fn () => StarLog::withoutQuerySensitiveColumns(['*' => ['password']], function (): void {
                SqlLoggingColumnModel::where('password', 'private password')->first();
                throw new RuntimeException('business error');
            }));
            $this->fail('The callback exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('business error', $exception->getMessage());
        }
        $this->assertFalse(StarLog::getConfig('query.enable'));
        $this->assertSame(1024, StarLog::getConfig('query.bindings.max_length'));
        StarLog::withQueryLogging(fn () => SqlLoggingColumnModel::where('password', 'private password')->first());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['pri…'])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['******'])->once();
    }

    public function test_entry_counts_are_not_reset_by_nested_options(): void
    {
        StarLog::withQueryLogOptions(['enable' => true, 'max_entries' => 1], function (): void {
            DB::select('select 1');
            StarLog::withQueryLogOptions(['max_entries' => 2], fn () => DB::select('select 2'));
            DB::select('select 3');
            StarLog::withQueryLogOptions(['max_entries' => 2], fn () => DB::select('select 4'));
        });
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['sql'] === 'select 1')->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['sql'] === 'select 2')->once();
        Log::shouldHaveReceived('info')->twice();
    }

    public function test_predicates_compose_with_existing_filters_and_do_not_recursively_log_their_queries(): void
    {
        $calls = (object) ['count' => 0];
        StarLog::withQueryLogging(fn () => StarLog::withQueryLogFilter(
            static function (QueryExecuted $query) use ($calls): bool {
                $calls->count++;
                DB::select('select 99');

                return $query->sql !== 'select 2';
            },
            fn () => StarLog::withQueryLogFilter(
                static fn (QueryExecuted $query): bool => $query->sql !== 'select 3',
                function (): void {
                    DB::select('select 1');
                    DB::select('select 2');
                    DB::select('select 3');
                    StarLog::withQueryLogOptions(['sample_rate' => 0], fn () => DB::select('select 4'));
                },
            ),
        ));
        $this->assertSame(3, $calls->count);
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['sql'] === 'select 1')->once();
        Log::shouldHaveReceived('info')->once();
    }

    public function test_predicate_exceptions_do_not_break_queries_and_predicates_restore_after_business_exceptions(): void
    {
        StarLog::withQueryLogging(function (): void {
            $rows = StarLog::withQueryLogFilter(
                static fn () => throw new RuntimeException('filter error'),
                fn () => DB::select('select 1'),
            );
            $this->assertCount(1, $rows);

            try {
                StarLog::withQueryLogFilter(static fn () => false, static fn () => throw new RuntimeException('business error'));
            } catch (RuntimeException $exception) {
                $this->assertSame('business error', $exception->getMessage());
            }
            DB::select('select 2');
        });
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['sql'] === 'select 2')->once();
        Log::shouldHaveReceived('info')->once();
    }
}
