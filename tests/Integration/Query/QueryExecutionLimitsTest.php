<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Query;

use Caixingyue\LaravelStarLog\Facades\StarLog as StarLogFacade;
use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Query\QueryLimitCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Query\QueryLimitJob;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class QueryExecutionLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'sync');
        config()->set('starlog.query.enable', true);
        config()->set('starlog.query.max_entries', 1);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        /** @var \Illuminate\Foundation\Console\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new QueryLimitCommand);
        Log::spy();
    }

    public function test_each_command_gets_its_own_entry_budget(): void
    {
        Artisan::call('star-log:query-limit');
        Artisan::call('star-log:query-limit');

        Log::shouldHaveReceived('info')->twice();
    }

    public function test_nested_commands_restore_the_parent_entry_budget(): void
    {
        Artisan::call('star-log:query-limit', ['--nested' => true]);

        Log::shouldHaveReceived('info')->twice();
    }

    public function test_each_sync_job_gets_its_own_entry_budget(): void
    {
        dispatch(new QueryLimitJob);
        dispatch(new QueryLimitJob);

        Log::shouldHaveReceived('info')->twice();
    }

    public function test_nested_sync_jobs_restore_the_parent_entry_budget(): void
    {
        dispatch(new QueryLimitJob(true));

        Log::shouldHaveReceived('info')->twice();
    }

    public function test_completion_listener_failure_does_not_reset_the_parent_entry_budget(): void
    {
        DB::select('select 1');
        Queue::after(static function (): void {
            throw new RuntimeException('Completion listener failed.');
        });

        try {
            dispatch(new QueryLimitJob);
            $this->fail('The completion listener should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Completion listener failed.', $exception->getMessage());
        }

        DB::select('select 2');

        Log::shouldHaveReceived('info')->twice();
    }

    public function test_starting_another_request_resets_its_entry_budget(): void
    {
        config()->set('starlog.route.request_id.share_log_context', false);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);

        foreach (['/first', '/second'] as $path) {
            (new AssignRequestId)->handle(Request::create($path), static function (): Response {
                DB::select('select 1');

                return new Response('OK');
            });
        }

        Log::shouldHaveReceived('info')->twice();
    }

    public function test_logging_suppression_still_applies_to_nested_jobs(): void
    {
        StarLogFacade::withoutQueryLogging(static function (): void {
            dispatch(new QueryLimitJob(true));
        });

        Log::shouldNotHaveReceived('info');
    }
}
