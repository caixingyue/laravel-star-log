<?php

namespace Caixingyue\LaravelStarLog\Tests\Queue;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\FailingQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\InspectableQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\NestedQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\UncorrelatedQueueJob;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class QueueCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        NestedQueueJob::$executions = [];
        NestedQueueJob::$afterNested = [];
        InspectableQueueJob::$executions = [];
        UncorrelatedQueueJob::$executions = [];
        FailingQueueJob::$executionChain = [];
        FailingQueueJob::$failureChain = [];
        config()->set('queue.default', 'sync');
    }

    public function test_correlated_job_appends_its_id_to_the_dispatcher_chain(): void
    {
        $chain = [
            ['id' => 101, 'name' => 'request', 'type' => 'request'],
            ['id' => 102, 'name' => 'artisan', 'type' => 'artisan'],
        ];
        StarLog::setCorrelationChain($chain);

        dispatch(new NestedQueueJob);

        $execution = NestedQueueJob::$executions[0];

        $this->assertSame($chain, array_slice($execution, 0, 2));
        $this->assertSame('queue', $execution[2]['type']);
        $this->assertSame(102, StarLog::getArtisanId());
    }

    public function test_nested_jobs_keep_each_queue_id_and_restore_the_parent_context(): void
    {
        StarLog::setCorrelationChain([
            ['id' => 101, 'name' => 'request', 'type' => 'request'],
        ]);

        dispatch(new NestedQueueJob(1));

        [$parent, $nested] = NestedQueueJob::$executions;

        $this->assertCount(2, $parent);
        $this->assertCount(3, $nested);
        $this->assertSame($parent[1]['id'], $nested[1]['id']);
        $this->assertNotSame($parent[1]['id'], $nested[2]['id']);
        $this->assertSame($parent, NestedQueueJob::$afterNested);
    }

    public function test_interacts_with_correlation_exposes_current_parent_and_complete_chain(): void
    {
        $request = ['id' => 101, 'name' => 'request', 'type' => 'request'];
        $artisan = ['id' => 102, 'name' => 'artisan', 'type' => 'artisan'];
        StarLog::setCorrelationChain([$request, $artisan]);

        dispatch(new InspectableQueueJob);

        $execution = InspectableQueueJob::$executions[0];

        $this->assertSame($execution['id'], $execution['queueId']);
        $this->assertSame($execution['id'], $execution['correlationId']);
        $this->assertSame($artisan, $execution['parent']);
        $this->assertSame(102, $execution['artisanId']);
        $this->assertSame(102, $execution['nearestArtisanId']);
        $this->assertSame('queue', $execution['chain'][2]['type']);
    }

    public function test_failing_job_restores_the_dispatcher_chain(): void
    {
        $chain = [
            ['id' => 101, 'name' => 'request', 'type' => 'request'],
            ['id' => 102, 'name' => 'artisan', 'type' => 'artisan'],
        ];
        StarLog::setCorrelationChain($chain);

        try {
            dispatch(new FailingQueueJob);
            $this->fail('The queued job should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue failed.', $exception->getMessage());
        }

        $this->assertSame($chain, StarLog::getCorrelationChain());
        $this->assertSame(FailingQueueJob::$executionChain, FailingQueueJob::$failureChain);
        $this->assertSame('queue', FailingQueueJob::$failureChain[2]['type']);
    }

    public function test_completion_listener_failure_restores_the_dispatcher_chain_once(): void
    {
        $chain = [['id' => 101, 'name' => 'request', 'type' => 'request']];
        StarLog::setCorrelationChain($chain);
        Queue::after(static function (): void {
            throw new RuntimeException('Completion listener failed.');
        });

        try {
            dispatch(new InspectableQueueJob);
            $this->fail('The completion listener should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Completion listener failed.', $exception->getMessage());
        }

        $this->assertSame($chain, StarLog::getCorrelationChain());
    }

    public function test_nested_completion_listener_failure_restores_each_parent_chain(): void
    {
        $chain = [['id' => 101, 'name' => 'request', 'type' => 'request']];
        StarLog::setCorrelationChain($chain);
        Queue::after(static function (JobProcessed $event): void {
            if (count($event->job->payload()['starLogCorrelationChain'] ?? []) === 3) {
                throw new RuntimeException('Nested completion listener failed.');
            }
        });

        try {
            dispatch(new NestedQueueJob(1));
            $this->fail('The nested completion listener should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nested completion listener failed.', $exception->getMessage());
        }

        $this->assertSame($chain, StarLog::getCorrelationChain());
    }

    public function test_failure_callback_exception_still_restores_the_dispatcher_chain(): void
    {
        $chain = [['id' => 101, 'name' => 'request', 'type' => 'request']];
        StarLog::setCorrelationChain($chain);

        try {
            dispatch(new FailingQueueJob(throwInFailed: true));
            $this->fail('The failure callback should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failure callback failed.', $exception->getMessage());
        }

        $this->assertSame(FailingQueueJob::$executionChain, FailingQueueJob::$failureChain);
        $this->assertSame($chain, StarLog::getCorrelationChain());
    }

    public function test_unrelated_and_duplicate_attempt_events_do_not_restore_another_context(): void
    {
        $chain = [['id' => 101, 'name' => 'request', 'type' => 'request']];
        StarLog::setCorrelationChain($chain);
        $processedJob = null;
        Queue::after(function (JobProcessed $event) use (&$processedJob): void {
            $processedJob = $event->job;
            $jobChain = StarLog::getCorrelationChain();
            $this->assertCount(2, $jobChain);
            $this->assertSame('queue', $jobChain[1]['type']);

            Event::dispatch(new JobAttempted('sync', new SyncJob($this->app, '{}', 'sync', 'default')));

            $this->assertSame($jobChain, StarLog::getCorrelationChain());
        });

        dispatch(new InspectableQueueJob);
        $this->assertSame($chain, StarLog::getCorrelationChain());

        Event::dispatch(new JobAttempted('sync', $processedJob));

        $this->assertSame($chain, StarLog::getCorrelationChain());
    }

    #[DataProvider('workerFailures')]
    public function test_worker_restores_context_after_failure_callbacks(bool $throwInFailed, string $expectedMessage): void
    {
        $chain = [['id' => 101, 'name' => 'request', 'type' => 'request']];
        StarLog::setCorrelationChain($chain);
        $jobChain = StarLog::createQueueCorrelationChain(FailingQueueJob::class);
        $payload = json_encode([
            'uuid' => 'star-log-worker-test',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => FailingQueueJob::class,
                'command' => serialize(new FailingQueueJob($throwInFailed)),
            ],
            'starLogCorrelationChain' => $jobChain,
        ], JSON_THROW_ON_ERROR);
        $job = new SyncJob($this->app, $payload, 'sync', 'default');

        try {
            $this->app->make('queue.worker')->process('sync', $job, new WorkerOptions(maxTries: 1));
            $this->fail('The worker job should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }

        $this->assertSame($jobChain, FailingQueueJob::$executionChain);
        $this->assertSame($jobChain, FailingQueueJob::$failureChain);
        $this->assertSame($chain, StarLog::getCorrelationChain());
    }

    public static function workerFailures(): array
    {
        return [
            'job failure' => [false, 'Queue failed.'],
            'failure callback exception' => [true, 'Failure callback failed.'],
        ];
    }

    public function test_uncorrelated_job_breaks_the_chain_for_a_correlated_child(): void
    {
        $chain = [
            ['id' => 101, 'name' => 'request', 'type' => 'request'],
            ['id' => 102, 'name' => 'artisan', 'type' => 'artisan'],
        ];
        StarLog::setCorrelationChain($chain);

        dispatch(new UncorrelatedQueueJob);

        $this->assertSame([[], []], UncorrelatedQueueJob::$executions);
        $this->assertCount(1, NestedQueueJob::$executions[0]);
        $this->assertSame('queue', NestedQueueJob::$executions[0][0]['type']);
        $this->assertSame($chain, StarLog::getCorrelationChain());
    }
}
