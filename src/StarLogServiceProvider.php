<?php

namespace Caixingyue\LaravelStarLog;

use Caixingyue\LaravelStarLog\Correlation\ExceptionLogContext;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogState;
use Caixingyue\LaravelStarLog\Http\Client\LoggingHttpClientFactory;
use Caixingyue\LaravelStarLog\Http\Client\Middleware\CaptureHttpClientLogSnapshot;
use Caixingyue\LaravelStarLog\Http\Client\Middleware\RequestConnectionFailureToLog;
use Caixingyue\LaravelStarLog\Listeners\Http\AddRequestIdToResponse;
use Caixingyue\LaravelStarLog\Listeners\Http\RequestHandledToLog;
use Caixingyue\LaravelStarLog\Listeners\HttpClientSubscriber;
use Caixingyue\LaravelStarLog\Providers\ConsoleServiceProvider;
use Caixingyue\LaravelStarLog\Providers\QueryServiceProvider;
use Caixingyue\LaravelStarLog\Providers\QueueServiceProvider;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Exceptions\InvalidPackage;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class StarLogServiceProvider extends PackageServiceProvider
{
    /**
     * Register Star Log services and dependent package providers.
     *
     * @throws InvalidPackage
     */
    public function register(): static
    {
        $this->app->scoped(HttpClientLogState::class);
        $this->app->scoped(HttpClientLogSnapshots::class);
        $this->app->singleton(Factory::class, LoggingHttpClientFactory::class);
        $this->app->singleton(ExceptionLogContext::class);

        $this->app->scoped(StarLog::class, fn (Application $app): StarLog => new StarLog(
            $app['request'],
            $app['config']->get('starlog'),
            new DailyIdGenerator(
                $app['cache']->store(),
                randomizationKey: (string) $app['config']->get('app.key', '')
            ),
            $app->make(QueryLogState::class),
            $app->make(HttpClientLogState::class),
        ));

        $this->app->register(ConsoleServiceProvider::class);
        $this->app->register(QueryServiceProvider::class);
        $this->app->register(QueueServiceProvider::class);

        return parent::register();
    }

    /**
     * Register HTTP-related event listeners.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        Event::subscribe(HttpClientSubscriber::class);

        Event::listen(RequestHandled::class, AddRequestIdToResponse::class);
        Event::listen(RequestHandled::class, RequestHandledToLog::class);

        $this->app->make(Factory::class)
            ->globalMiddleware(new CaptureHttpClientLogSnapshot)
            ->globalMiddleware(new RequestConnectionFailureToLog);

        $this->registerExceptionLogContext();

        parent::boot();
    }

    /**
     * Supply originating command and queue IDs to Laravel's exception logs.
     *
     * @throws BindingResolutionException
     */
    private function registerExceptionLogContext(): void
    {
        $exceptionContext = $this->app->make(ExceptionLogContext::class);

        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler) use ($exceptionContext): void {
            if (is_callable([$handler, 'buildContextUsing'])) {
                $handler->buildContextUsing(fn (Throwable $exception): array => $exceptionContext->forException($exception));
            }
        });
    }

    /**
     * Configure the package's configuration file and translations.
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package->name('laravel-star-log')->hasConfigFile('starlog')->hasTranslations();
    }
}
