<?php

namespace Caixingyue\LaravelStarLog;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class QueueServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::before(function (JobProcessing $event) {
            $payload = $event->job->payload();
            $command = $payload['data']['command'];

            $serialize = unserialize($command);
            StarLog::loadObjectStarLogIds($serialize);
        });
    }
}
