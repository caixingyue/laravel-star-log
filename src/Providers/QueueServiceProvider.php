<?php

namespace Caixingyue\LaravelStarLog\Providers;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Correlation\ExceptionLogContext;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\Queue\Connectors\SyncConnector;
use Caixingyue\LaravelStarLog\Queue\CorrelatedSyncQueue;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class QueueServiceProvider extends ServiceProvider
{
    /**
     * Parent contexts for active queue attempts, matched to their job instances.
     *
     * @var array<int, array{job: Job, chain: array}>
     */
    private array $executionContextStack = [];

    /**
     * Register the synchronous driver with dispatch-time correlation capture.
     */
    public function register(): void
    {
        $this->app->afterResolving('queue', static function (QueueManager $manager): void {
            $manager->addConnector('sync', static fn (): SyncConnector => new SyncConnector);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        Queue::createPayloadUsing(function ($connectionName, $_queue, array $payload): array {
            $job = data_get($payload, 'data.commandName');

            if (! $job instanceof ShouldBeCorrelated) {
                return [];
            }

            $connection = $this->app->make('queue')->connection($connectionName);
            $chain = $connection instanceof CorrelatedSyncQueue
                ? $connection->getDispatchCorrelationChain($job)
                : null;

            return ['starLogCorrelationChain' => $chain ?? StarLog::createQueueCorrelationChain($job)];
        });

        Queue::before(function (JobProcessing $event) {
            $this->executionContextStack[] = [
                'job' => $event->job,
                'chain' => StarLog::getCorrelationChain(),
            ];

            $this->app->make(QueryLogState::class)->beginExecution();

            $payload = $event->job->payload();

            StarLog::setCorrelationChain([]);

            if (isset($payload['starLogCorrelationChain']) && is_array($payload['starLogCorrelationChain'])) {
                StarLog::setCorrelationChain($payload['starLogCorrelationChain']);
            }
        });

        // Both synchronous queues and workers dispatch this from finally, after failure callbacks.
        Event::listen(JobAttempted::class, fn (JobAttempted $event) => $this->restoreExecutionContext($event->job));

        $this->registerExceptionLogContext();
    }

    /**
     * Capture originating IDs before the failed queue attempt finishes.
     *
     * @throws BindingResolutionException
     */
    private function registerExceptionLogContext(): void
    {
        $exceptionContext = $this->app->make(ExceptionLogContext::class);

        Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event) use ($exceptionContext): void {
            $exceptionContext->remember($event->exception, StarLog::getCorrelationChain());
        });
    }

    /**
     * Restore the matching parent context once, after the entire queue attempt finishes.
     *
     * @throws BindingResolutionException
     */
    private function restoreExecutionContext(Job $job): void
    {
        $context = $this->executionContextStack[array_key_last($this->executionContextStack)] ?? null;

        if ($context === null || $context['job'] !== $job) {
            return;
        }

        array_pop($this->executionContextStack);

        StarLog::setCorrelationChain($context['chain']);
        $this->app->make(QueryLogState::class)->finishExecution();
    }
}
