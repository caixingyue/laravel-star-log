<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class LifecycleProbeJob implements ShouldBeCorrelated, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public static array $executions = [];

    public static array $failures = [];

    public static array $afterNested = [];

    public function __construct(public readonly string $mode = 'success') {}

    public function handle(): void
    {
        self::$executions[] = [
            'chain' => StarLog::getCorrelationChain(),
            'attempt' => $this->job?->attempts(),
        ];

        DB::select('select 42');
        DB::select('select 42');

        if ($this->mode === 'nested_sync') {
            Bus::dispatchSync(new InspectableQueueJob);
            self::$afterNested = StarLog::getCorrelationChain();
        }

        if ($this->mode === 'nested_async') {
            dispatch(new InspectableQueueJob);
            self::$afterNested = StarLog::getCorrelationChain();
        }

        if ($this->mode === 'release' && $this->job?->attempts() === 1) {
            $this->release(0);

            return;
        }

        if ($this->mode === 'manual_failure') {
            $this->fail(new RuntimeException('Manually failed.'));

            return;
        }

        if (in_array($this->mode, ['job_failure', 'failure_callback', 'exception_listener', 'failed_listener'], true)
            || ($this->mode === 'retry' && $this->job?->attempts() === 1)) {
            throw new RuntimeException('Lifecycle job failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        self::$failures[] = StarLog::getCorrelationChain();

        if ($this->mode === 'failure_callback') {
            throw new RuntimeException('Failure callback failed.');
        }
    }
}
