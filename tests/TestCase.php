<?php

namespace Caixingyue\LaravelStarLog\Tests;

use Caixingyue\LaravelStarLog\StarLogServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Enable automatic package discovery in the Testbench application.
     */
    protected $enablesPackageDiscoveries = true;

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [StarLogServiceProvider::class];
    }

    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel skips this event bridge in tests; exercise the production command lifecycle.
        /** @var \Illuminate\Foundation\Console\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
    }
}
