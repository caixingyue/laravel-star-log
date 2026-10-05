<?php

namespace Caixingyue\LaravelStarLog\Tests\Listeners;

require_once __DIR__ . '/../Fixtures/StarLogEntryQueryCaller.php';

use App\StarLogTestFixtures\StarLogEntryQueryCaller;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;

final class QueryExecutedToLogTest extends TestCase
{
    public function test_binding_settings_follow_pdo_positions_for_reordered_numeric_keys(): void
    {
        DB::insert('insert into star_log_query_entries (password, profile) values (?, ?)', [
            1 => 'abcdef', 0 => 'synthetic-secret',
        ]);

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === [
            1 => 'abc…', 0 => '******',
        ])->once();
        $this->assertSame('synthetic-secret', DB::table('star_log_query_entries')->value('password'));
    }

    public function test_valid_commented_insert_columns_mask_only_sensitive_bindings(): void
    {
        DB::insert('insert into star_log_query_entries (password /* note */, profile) values (?, ?)', [
            'synthetic-secret', 'abcdef',
        ]);

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['bindings'] === ['******', 'abc…'])->once();
        $this->assertSame('synthetic-secret', DB::table('star_log_query_entries')->value('password'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('star_log_query_entries');
        Schema::create('star_log_query_entries', function ($table): void {
            $table->string('password');
            $table->string('profile');
        });

        config()->set('starlog.query', [
            'enable' => true,
            'min_time' => 0,
            'sample_rate' => 1.0,
            'max_entries' => null,
            'max_sql_length' => null,
            'bindings' => [
                'enable' => true,
                'max_length' => 1024,
                'max_count' => 50,
                'columns' => [
                    '*' => [
                        'password' => ['sensitive' => true],
                        'profile' => ['max_length' => 3],
                    ],
                ],
            ],
            'ignore' => [
                'global' => [],
                'classes' => [],
            ],
        ]);

        $this->app->forgetScopedInstances();
        Log::spy();
    }

    public function test_listener_writes_safe_context_for_an_eligible_query(): void
    {
        $connectionName = DB::connection()->getName();
        DB::insert(
            'insert into star_log_query_entries (password, profile) values (?, ?)',
            ['secret-value', 'abcdef']
        );

        Log::shouldHaveReceived('info')
            ->with(Mockery::on(static function (mixed $message) use ($connectionName): bool {
                return is_string($message)
                    && str_starts_with($message, "Connection[{$connectionName}] - Duration[")
                    && str_ends_with($message, 'ms]');
            }), Mockery::on(function (array $context): bool {
                return $context['sql'] === 'insert into star_log_query_entries (password, profile) values (?, ?)'
                    && $context['bindings'] === ['******', 'abc…']
                    && ! array_key_exists('connection', $context)
                    && ! array_key_exists('time_ms', $context);
            }))
            ->once();
    }

    public function test_listener_omits_bindings_for_a_parameterless_query(): void
    {
        DB::select('select * from star_log_query_entries');

        Log::shouldHaveReceived('info')
            ->with(Mockery::type('string'), Mockery::on(static function (array $context): bool {
                return $context['sql'] === 'select * from star_log_query_entries'
                    && ! array_key_exists('bindings', $context);
            }))
            ->once();
    }

    public function test_class_ignore_rules_apply_to_the_direct_query_caller(): void
    {
        $this->configureQueryLogging([
            'ignore' => ['classes' => [
                StarLogEntryQueryCaller::class => [
                    ['table' => 'star_log_query_entries'],
                ],
            ]],
        ]);

        (new StarLogEntryQueryCaller)->run();

        Log::shouldNotHaveReceived('info');
    }

    public function test_global_table_ignore_rules_apply_to_every_query_caller(): void
    {
        $this->configureQueryLogging([
            'ignore' => ['global' => [
                ['table' => 'star_log_query_entries'],
            ]],
        ]);

        DB::select('select * from star_log_query_entries');

        Log::shouldNotHaveReceived('info');
    }

    public function test_global_sql_fragment_ignore_rules_apply_to_every_query_caller(): void
    {
        $this->configureQueryLogging([
            'ignore' => ['global' => [
                ['contains' => 'select * from star_log_query_entries'],
            ]],
        ]);

        DB::select('select * from star_log_query_entries');

        Log::shouldNotHaveReceived('info');
    }

    public function test_ignore_rules_require_every_configured_condition_to_match(): void
    {
        $this->configureQueryLogging([
            'ignore' => ['classes' => [
                StarLogEntryQueryCaller::class => [
                    ['table' => 'another_table', 'contains' => 'select'],
                ],
            ]],
        ]);

        (new StarLogEntryQueryCaller)->run();

        Log::shouldHaveReceived('info')->once();
    }

    public function test_resuming_a_dynamically_ignored_table_restores_its_query_logging(): void
    {
        StarLog::ignoreQueryTable('star_log_query_entries');
        DB::select('select * from star_log_query_entries');
        StarLog::resumeQueryTable('star_log_query_entries');
        DB::select('select * from star_log_query_entries');

        Log::shouldHaveReceived('info')->once();
    }

    public function test_entry_limits_apply_within_the_current_execution(): void
    {
        $this->configureQueryLogging(['max_entries' => 1]);

        DB::select('select * from star_log_query_entries');
        DB::select('select * from star_log_query_entries');

        Log::shouldHaveReceived('info')->once();
    }

    public function test_minimum_duration_filters_fast_queries(): void
    {
        $this->configureQueryLogging(['min_time' => 999]);

        DB::select('select * from star_log_query_entries');

        Log::shouldNotHaveReceived('info');
    }

    public function test_zero_sample_rate_excludes_every_query(): void
    {
        $this->configureQueryLogging(['sample_rate' => 0.0]);

        DB::select('select * from star_log_query_entries');

        Log::shouldNotHaveReceived('info');
    }

    public function test_disabled_query_logging_does_not_write_query_context(): void
    {
        $this->configureQueryLogging(['enable' => false]);

        DB::select('select * from star_log_query_entries');

        Log::shouldNotHaveReceived('info');
    }

    public function test_sql_text_is_truncated_only_in_the_log_context(): void
    {
        $this->configureQueryLogging(['max_sql_length' => 10]);

        DB::select('select * from star_log_query_entries');

        Log::shouldHaveReceived('info')
            ->with(Mockery::type('string'), Mockery::on(static function (array $context): bool {
                return $context['sql'] === 'select * f…';
            }))
            ->once();
    }

    /**
     * Apply one or more query logging configuration overrides to a fresh execution state.
     */
    private function configureQueryLogging(array $overrides): void
    {
        config()->set('starlog.query', array_replace_recursive(config('starlog.query'), $overrides));
        $this->app->forgetScopedInstances();
    }
}
