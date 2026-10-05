<?php

namespace Caixingyue\LaravelStarLog\Providers;

use Caixingyue\LaravelStarLog\Listeners\QueryExecutedToLog;
use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class QueryServiceProvider extends ServiceProvider
{
    /**
     * Register query logging services.
     */
    public function register(): void
    {
        $this->app->scoped(QueryLogState::class);
        $this->app->singleton(QueryBindingColumnRegistry::class);
    }

    /**
     * Listen for database query events.
     */
    public function boot(QueryExecutedToLog $listener): void
    {
        DB::listen(fn (QueryExecuted $event) => $listener->handle($event));
    }
}
