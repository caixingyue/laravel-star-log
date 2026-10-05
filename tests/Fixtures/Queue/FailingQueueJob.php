<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;
use Throwable;

final class FailingQueueJob implements ShouldBeCorrelated, ShouldQueue
{
    use Queueable;

    public static array $executionChain = [];

    public static array $failureChain = [];

    public function __construct(private readonly bool $throwInFailed = false) {}

    public function handle(): void
    {
        self::$executionChain = StarLog::getCorrelationChain();

        throw new RuntimeException('Queue failed.');
    }

    public function failed(?Throwable $exception): void
    {
        self::$failureChain = StarLog::getCorrelationChain();

        if ($this->throwInFailed) {
            throw new RuntimeException('Failure callback failed.');
        }
    }
}
