<?php

namespace Caixingyue\LaravelStarLog;

use Caixingyue\LaravelStarLog\Listeners\HttpSubscribe;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Exceptions\InvalidPackage;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class StarLogServiceProvider extends PackageServiceProvider
{
    /**
     * Register any application services.
     *
     * @return StarLogServiceProvider|void
     * @throws InvalidPackage
     */
    public function register()
    {
        $this->app->bind(StarLog::class, function (Application $app) {
            return new StarLog($app['request'], config('starlog'));
        });

        $this->app->register(QueryServiceProvider::class);
        $this->app->register(QueueServiceProvider::class);

        return parent::register();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(HttpSubscribe::class);

        parent::boot();
    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package->name('laravel-star-log')->hasConfigFile('starlog');
    }
}
