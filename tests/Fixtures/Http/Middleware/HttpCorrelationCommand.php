<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Http\Middleware;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;

final class HttpCorrelationCommand extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'star-log:http-correlation-test';

    public static array $executions = [];

    public function handle(): int
    {
        self::$executions[] = $this->snapshot();

        dispatch(new HttpCorrelationJob);

        self::$executions[] = $this->snapshot();

        return self::SUCCESS;
    }

    private function snapshot(): array
    {
        return [
            'id' => $this->getId(),
            'requestId' => $this->getRequestId(),
            'chain' => $this->getCorrelationChain(),
        ];
    }
}
