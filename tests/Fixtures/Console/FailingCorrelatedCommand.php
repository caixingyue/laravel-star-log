<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;
use RuntimeException;

final class FailingCorrelatedCommand extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'star-log:failing-correlation-test';

    public static array $executions = [];

    public function handle(): int
    {
        self::$executions[] = $this->getCorrelationChain();

        throw new RuntimeException('Command failed.');
    }
}
