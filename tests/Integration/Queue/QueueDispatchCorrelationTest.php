<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Queue;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\AfterCommitDispatchCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\AfterCommitDispatchSnapshotQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\DispatchSnapshotQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\FailingQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Queue\UniqueDispatchSnapshotQueueJob;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class QueueDispatchCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync', ['driver' => 'sync', 'after_commit' => false]);
        DispatchSnapshotQueueJob::$executions = [];
        AfterCommitDispatchCommand::$executions = [];
        FailingQueueJob::$executionChain = [];
        FailingQueueJob::$failureChain = [];
        $this->app->make(Kernel::class)->registerCommand(new AfterCommitDispatchCommand);
    }

    public function test_outer_transaction_preserves_the_finished_dispatching_command(): void
    {
        $parent = $this->requestChain(101);
        StarLog::setCorrelationChain($parent);

        DB::transaction(function () use ($parent): void {
            $this->assertSame(0, Artisan::call('star-log:after-commit-dispatch'));
            $this->assertSame([], DispatchSnapshotQueueJob::$executions);
            $this->assertSame($parent, StarLog::getCorrelationChain());
        });

        $commandChain = AfterCommitDispatchCommand::$executions[0];
        $this->assertSame('artisan', $commandChain[1]['type']);
        $this->assertSource($commandChain, DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSame($parent, StarLog::getCorrelationChain());
    }

    public function test_transaction_inside_command_still_executes_before_command_returns(): void
    {
        $parent = $this->requestChain(101);
        StarLog::setCorrelationChain($parent);

        $this->assertSame(0, Artisan::call('star-log:after-commit-dispatch'));

        $this->assertCount(1, DispatchSnapshotQueueJob::$executions);
        $this->assertSource(AfterCommitDispatchCommand::$executions[0], DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSame($parent, StarLog::getCorrelationChain());
    }

    #[DataProvider('afterCommitSettings')]
    public function test_after_commit_settings_preserve_dispatch_source_on_named_sync_connection(string $setting): void
    {
        config()->set('queue.connections.snapshot-sync', [
            'driver' => 'sync',
            'after_commit' => $setting === 'connection',
        ]);
        $job = $setting === 'interface' ? new AfterCommitDispatchSnapshotQueueJob : new DispatchSnapshotQueueJob;
        $job->onConnection('snapshot-sync');

        if ($setting === 'job') {
            $job->afterCommit();
        }
        $source = $this->requestChain(101);
        $caller = $this->requestChain(202);
        StarLog::setCorrelationChain($source);
        DB::beginTransaction();

        dispatch($job);
        $this->assertSame([], DispatchSnapshotQueueJob::$executions);
        $this->assertSame($source, StarLog::getCorrelationChain());
        StarLog::setCorrelationChain($caller);
        DB::commit();

        $this->assertSource($source, DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSame($caller, StarLog::getCorrelationChain());
    }

    public static function afterCommitSettings(): array
    {
        return ['job' => ['job'], 'connection' => ['connection'], 'interface' => ['interface']];
    }

    public function test_before_commit_overrides_connection_setting(): void
    {
        config()->set('queue.connections.snapshot-sync', ['driver' => 'sync', 'after_commit' => true]);
        $source = $this->requestChain(101);
        StarLog::setCorrelationChain($source);
        DB::beginTransaction();

        dispatch((new DispatchSnapshotQueueJob)->onConnection('snapshot-sync')->beforeCommit());

        $this->assertCount(1, DispatchSnapshotQueueJob::$executions);
        $this->assertSource($source, DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSame($source, StarLog::getCorrelationChain());
        DB::commit();
        $this->assertCount(1, DispatchSnapshotQueueJob::$executions);
    }

    public function test_reusing_one_job_object_captures_each_dispatch_separately(): void
    {
        $first = $this->requestChain(101);
        $second = $this->requestChain(202);
        $caller = $this->requestChain(303);
        $job = (new DispatchSnapshotQueueJob)->afterCommit();
        DB::beginTransaction();
        StarLog::setCorrelationChain($first);
        dispatch($job);
        StarLog::setCorrelationChain($second);
        dispatch($job);
        $this->assertSame([], DispatchSnapshotQueueJob::$executions);
        StarLog::setCorrelationChain($caller);

        DB::commit();

        $this->assertCount(2, DispatchSnapshotQueueJob::$executions);
        [$firstExecution, $secondExecution] = DispatchSnapshotQueueJob::$executions;
        $this->assertSource($first, $firstExecution['chain']);
        $this->assertSource($second, $secondExecution['chain']);
        $this->assertNotSame($firstExecution['chain'][1]['id'], $secondExecution['chain'][1]['id']);
        $this->assertSame($caller, StarLog::getCorrelationChain());
    }

    public function test_inner_rollback_discards_only_its_dispatch_snapshot(): void
    {
        $first = $this->requestChain(101);
        $rolledBack = $this->requestChain(202);
        $last = $this->requestChain(303);
        $caller = $this->requestChain(404);
        $job = (new DispatchSnapshotQueueJob)->afterCommit();
        DB::beginTransaction();
        StarLog::setCorrelationChain($first);
        dispatch($job);
        DB::beginTransaction();
        StarLog::setCorrelationChain($rolledBack);
        dispatch($job);
        DB::rollBack();
        StarLog::setCorrelationChain($last);
        dispatch($job);
        StarLog::setCorrelationChain($caller);

        DB::commit();

        $this->assertCount(2, DispatchSnapshotQueueJob::$executions);
        $this->assertSource($first, DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSource($last, DispatchSnapshotQueueJob::$executions[1]['chain']);
        $this->assertSame($caller, StarLog::getCorrelationChain());
    }

    public function test_outer_rollback_keeps_unique_job_unlock_behavior(): void
    {
        $source = $this->requestChain(101);
        StarLog::setCorrelationChain($source);
        $job = (new UniqueDispatchSnapshotQueueJob)->afterCommit();
        $contender = Cache::lock(UniqueLock::getKey($job));
        DB::beginTransaction();
        dispatch($job);
        $this->assertFalse($contender->get());

        DB::rollBack();

        $this->assertSame([], DispatchSnapshotQueueJob::$executions);
        $this->assertTrue($contender->get());
        $contender->release();
        $this->assertSame($source, StarLog::getCorrelationChain());
        $nextSource = $this->requestChain(202);
        StarLog::setCorrelationChain($nextSource);
        dispatch($job);
        $this->assertCount(1, DispatchSnapshotQueueJob::$executions);
        $this->assertSource($nextSource, DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertTrue($contender->get());
        $contender->release();
    }

    public function test_snapshot_preserves_other_payload_hooks_without_changing_caller_context(): void
    {
        $source = $this->requestChain(101);
        $caller = $this->requestChain(202);
        $observedCaller = null;
        $observedPayload = null;
        Queue::createPayloadUsing(static function ($connection, $queue, array $payload) use (&$observedCaller): array {
            $observedCaller = StarLog::getCorrelationChain();

            return ['otherPackageData' => 'retained'];
        });
        Queue::before(static function ($event) use (&$observedPayload): void {
            $observedPayload = $event->job->payload();
        });
        StarLog::setCorrelationChain($source);
        DB::beginTransaction();
        dispatch((new DispatchSnapshotQueueJob)->afterCommit());
        StarLog::setCorrelationChain($caller);

        DB::commit();

        $this->assertSame($caller, $observedCaller);
        $this->assertSame('retained', $observedPayload['otherPackageData']);
        $this->assertSource($source, $observedPayload['starLogCorrelationChain']);
        $this->assertSame($observedPayload['starLogCorrelationChain'], DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSame($caller, StarLog::getCorrelationChain());
    }

    public function test_business_data_is_still_serialized_at_execution_time(): void
    {
        $source = $this->requestChain(101);
        StarLog::setCorrelationChain($source);
        $job = (new DispatchSnapshotQueueJob('before'))->afterCommit();
        DB::beginTransaction();
        dispatch($job);
        $job->value = 'after';
        StarLog::setCorrelationChain($this->requestChain(202));

        DB::commit();

        $this->assertSame('after', DispatchSnapshotQueueJob::$executions[0]['value']);
        $this->assertSource($source, DispatchSnapshotQueueJob::$executions[0]['chain']);
    }

    #[DataProvider('failureCallbacks')]
    public function test_delayed_failure_restores_commit_caller_and_clears_snapshot(bool $throwInFailed): void
    {
        $source = $this->requestChain(101);
        $caller = $this->requestChain(202);
        StarLog::setCorrelationChain($source);
        DB::beginTransaction();
        dispatch((new FailingQueueJob($throwInFailed))->afterCommit());
        StarLog::setCorrelationChain($caller);

        try {
            DB::commit();
            $this->fail('The after-commit job should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($throwInFailed ? 'Failure callback failed.' : 'Queue failed.', $exception->getMessage());
        }

        $this->assertSource($source, FailingQueueJob::$executionChain);
        $this->assertSame(FailingQueueJob::$executionChain, FailingQueueJob::$failureChain);
        $this->assertSame($caller, StarLog::getCorrelationChain());
        dispatch(new DispatchSnapshotQueueJob);
        $this->assertSource($caller, DispatchSnapshotQueueJob::$executions[0]['chain']);
        $this->assertSame($caller, StarLog::getCorrelationChain());
    }

    public static function failureCallbacks(): array
    {
        return ['ordinary failure' => [false], 'failed callback throws' => [true]];
    }

    private function requestChain(int $id): array
    {
        return [['id' => $id, 'name' => 'request', 'type' => 'request']];
    }

    private function assertSource(array $source, array $chain): void
    {
        $this->assertSame($source, array_slice($chain, 0, -1));
        $this->assertSame('queue', $chain[array_key_last($chain)]['type']);
    }
}
