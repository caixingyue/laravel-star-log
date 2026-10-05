<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Queue;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\StarLog as StarLogImplementation;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\InspectableQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\LifecycleProbeJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\NestedQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\UncorrelatedQueueJob;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class QueueLifecycleIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        config()->set('queue.failed.driver', 'null');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config()->set('starlog.query.enable', true);
        config()->set('starlog.query.max_entries', 1);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
        LifecycleProbeJob::$executions = [];
        LifecycleProbeJob::$failures = [];
        LifecycleProbeJob::$afterNested = [];
        InspectableQueueJob::$executions = [];
        UncorrelatedQueueJob::$executions = [];
        NestedQueueJob::$executions = [];
        Log::spy();
    }

    #[DataProvider('lifecycleCases')]
    public function test_success_and_callback_failures_preserve_context_and_query_budget(string $driver, string $mode, ?string $expectedError, bool $runs): void
    {
        config()->set('queue.default', $driver);
        $parent = $this->parentChain();
        StarLog::setCorrelationChain($parent);
        DB::select('select 41');
        $observed = [];

        Queue::before(static function ($event) use (&$observed, $mode): void {
            $observed[] = StarLog::getCorrelationChain();

            if ($mode === 'before_listener') {
                throw new RuntimeException('Before listener failed.');
            }
        });
        Queue::after(static function ($event) use (&$observed, $mode): void {
            $observed[] = StarLog::getCorrelationChain();

            if ($mode === 'completion_listener') {
                throw new RuntimeException('Completion listener failed.');
            }
        });
        Queue::exceptionOccurred(static function ($event) use (&$observed, $mode): void {
            $observed[] = StarLog::getCorrelationChain();

            if ($mode === 'exception_listener') {
                throw new RuntimeException('Exception listener failed.');
            }
        });
        Queue::failing(static function ($event) use (&$observed, $mode): void {
            $observed[] = StarLog::getCorrelationChain();

            if ($mode === 'failed_listener') {
                throw new RuntimeException('Failed listener failed.');
            }
        });
        Event::listen(JobAttempted::class, function () use ($mode, $parent): void {
            $this->assertSame($parent, StarLog::getCorrelationChain());

            if ($mode === 'attempt_listener') {
                throw new RuntimeException('Attempt listener failed.');
            }
        });

        $error = null;

        try {
            dispatch(new LifecycleProbeJob($mode));

            if ($driver === 'database') {
                $this->assertSame([], LifecycleProbeJob::$executions);
                $this->assertSame($parent, StarLog::getCorrelationChain());
                $this->processNext();
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        $this->assertSame($expectedError, $error);
        $this->assertSame($parent, StarLog::getCorrelationChain());
        $this->assertCount($runs ? 1 : 0, LifecycleProbeJob::$executions);
        $jobChain = $observed[0];
        $this->assertSame($parent, array_slice($jobChain, 0, 1));
        $this->assertSame('queue', $jobChain[1]['type']);

        foreach ($observed as $chain) {
            $this->assertSame($jobChain, $chain);
        }

        foreach (LifecycleProbeJob::$failures as $chain) {
            $this->assertSame($jobChain, $chain);
        }
        DB::select('select 43');
        Log::shouldNotHaveReceived('info', [
            Mockery::any(),
            Mockery::on(static fn ($context) => ($context['sql'] ?? null) === 'select 43'),
        ]);

        if ($runs) {
            Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context = []) => ($context['sql'] ?? null) === 'select 42')->once();
        }
    }

    public static function lifecycleCases(): array
    {
        $cases = [];

        foreach (['sync', 'database'] as $driver) {
            foreach ([
                'success' => [null, true],
                'job_failure' => ['Lifecycle job failed.', true],
                'failure_callback' => ['Failure callback failed.', true],
                'before_listener' => ['Before listener failed.', false],
                'completion_listener' => ['Completion listener failed.', true],
                'exception_listener' => ['Exception listener failed.', true],
                'failed_listener' => ['Failed listener failed.', true],
                'attempt_listener' => ['Attempt listener failed.', true],
                'manual_failure' => [null, true],
            ] as $mode => [$error, $runs]) {
                $cases["{$driver}: {$mode}"] = [$driver, $mode, $error, $runs];
            }
        }

        return $cases;
    }

    #[DataProvider('retryCases')]
    public function test_retries_and_releases_keep_the_job_id_and_reset_the_attempt_budget(string $mode): void
    {
        config()->set('queue.default', 'database');
        $parent = $this->parentChain();
        StarLog::setCorrelationChain($parent);
        dispatch(new LifecycleProbeJob($mode));

        try {
            $this->processNext(maxTries: 2);
        } catch (RuntimeException $exception) {
            $this->assertSame('retry', $mode);
            $this->assertSame('Lifecycle job failed.', $exception->getMessage());
        }
        $this->assertSame($parent, StarLog::getCorrelationChain());
        $this->processNext(maxTries: 2);

        $this->assertSame([1, 2], array_column(LifecycleProbeJob::$executions, 'attempt'));
        $this->assertSame(LifecycleProbeJob::$executions[0]['chain'], LifecycleProbeJob::$executions[1]['chain']);
        $this->assertSame($parent, StarLog::getCorrelationChain());
        $this->assertSame(0, Queue::connection('database')->size());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context = []) => ($context['sql'] ?? null) === 'select 42')->twice();
    }

    public static function retryCases(): array
    {
        return [['retry'], ['release']];
    }

    #[DataProvider('nestedCases')]
    public function test_database_worker_preserves_the_parent_when_dispatching_nested_work(string $mode): void
    {
        config()->set('queue.default', 'database');
        $parent = $this->parentChain();
        StarLog::setCorrelationChain($parent);
        dispatch(new LifecycleProbeJob($mode));
        $this->processNext();

        $jobChain = LifecycleProbeJob::$executions[0]['chain'];
        $this->assertSame($jobChain, LifecycleProbeJob::$afterNested);
        $this->assertSame($parent, StarLog::getCorrelationChain());

        if ($mode === 'nested_async') {
            $this->assertSame([], InspectableQueueJob::$executions);
            $this->processNext();
        }
        $childChain = InspectableQueueJob::$executions[0]['chain'];
        $this->assertSame($jobChain, array_slice($childChain, 0, 2));
        $this->assertNotSame($jobChain[1]['id'], $childChain[2]['id']);
        $this->assertSame($parent, StarLog::getCorrelationChain());
    }

    public static function nestedCases(): array
    {
        return [['nested_sync'], ['nested_async']];
    }

    public function test_daemon_resets_scoped_state_between_correlated_and_uncorrelated_jobs(): void
    {
        config()->set('queue.default', 'database');
        StarLog::setCorrelationChain($this->parentChain());
        dispatch(new LifecycleProbeJob);
        StarLog::setCorrelationChain([['id' => 202, 'name' => 'second-request', 'type' => 'request']]);
        dispatch(new LifecycleProbeJob);
        dispatch(new UncorrelatedQueueJob);

        $this->app->make('queue.worker')->daemon('database', 'default', new WorkerOptions(sleep: 0, maxJobs: 3, stopWhenEmpty: true));

        $this->assertSame(101, LifecycleProbeJob::$executions[0]['chain'][0]['id']);
        $this->assertSame(202, LifecycleProbeJob::$executions[1]['chain'][0]['id']);
        $this->assertNotSame(LifecycleProbeJob::$executions[0]['chain'][1]['id'], LifecycleProbeJob::$executions[1]['chain'][1]['id']);
        $this->assertSame([[], []], UncorrelatedQueueJob::$executions);
        $this->assertSame([], StarLog::getCorrelationChain());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context = []) => ($context['sql'] ?? null) === 'select 42')->twice();
    }

    private function parentChain(): array
    {
        return [['id' => 101, 'name' => 'request', 'type' => 'request']];
    }

    private function processNext(int $maxTries = 1): void
    {
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $this->app->make('queue.worker')->process('database', $job, new WorkerOptions(maxTries: $maxTries, backoff: 0));
    }
}
