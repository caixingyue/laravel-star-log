<?php

namespace Caixingyue\LaravelStarLog\Queue;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\SyncQueue;
use Throwable;

/**
 * Capture each marked job's correlation chain before scheduling its execution.
 * Task serialization, execution events, and exception handling remain with SyncQueue.
 *
 * @internal
 */
class CorrelatedSyncQueue extends SyncQueue
{
    /**
     * @var array{job: ShouldBeCorrelated, chain: array}|null
     */
    private ?array $dispatchContext = null;

    /**
     * Capture a separate chain for each dispatch, including repeated use of one job object.
     *
     * @param  object|string  $job
     *
     * @throws Throwable
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        if (! $job instanceof ShouldBeCorrelated) {
            return parent::push($job, $data, $queue);
        }

        $chain = StarLog::createQueueCorrelationChain($job);
        $execute = fn () => $this->executeWithCorrelation($job, $data, $queue, $chain);

        if ($this->shouldDispatchAfterCommit($job) && $this->container->bound('db.transactions')) {
            $this->registerAfterCommitRollbackCallbacks($job);

            return $this->container->make('db.transactions')->addCallback($execute);
        }

        return $execute();
    }

    /**
     * Return the matching dispatch's snapshot to the payload hook.
     */
    public function getDispatchCorrelationChain(ShouldBeCorrelated $job): ?array
    {
        if ($this->dispatchContext === null || $this->dispatchContext['job'] !== $job) {
            return null;
        }

        return $this->dispatchContext['chain'];
    }

    /**
     * Expose the snapshot to the payload hook without changing the caller's active chain.
     *
     * @throws Throwable
     */
    private function executeWithCorrelation(ShouldBeCorrelated $job, mixed $data, mixed $queue, array $chain): int
    {
        $previous = $this->dispatchContext;
        $this->dispatchContext = ['job' => $job, 'chain' => $chain];

        try {
            return parent::executeJob($job, $data, $queue);
        } finally {
            $this->dispatchContext = $previous;
        }
    }

    /**
     * Preserve the framework's rollback behavior on both supported Laravel versions.
     *
     * @throws BindingResolutionException
     */
    private function registerAfterCommitRollbackCallbacks(ShouldBeCorrelated $job): void
    {
        if (method_exists($this, 'registerRollbackCallbacksForJobsThatDispatchAfterCommit')) {
            $this->registerRollbackCallbacksForJobsThatDispatchAfterCommit($job);

            return;
        }

        // Laravel 12 registers unique-job rollback cleanup directly in SyncQueue::push().
        if ($job instanceof ShouldBeUnique) {
            $this->container->make('db.transactions')->addCallbackForRollback(
                function () use ($job): void {
                    (new UniqueLock($this->container->make(Cache::class)))->release($job);
                }
            );
        }
    }
}
