<?php

namespace Caixingyue\LaravelStarLog\Tests;

use Caixingyue\LaravelStarLog\StarLog;
use Illuminate\Support\Facades\Facade;

final class StarLogTranslationTest extends TestCase
{
    public function test_package_locale_overrides_the_configured_application_locale(): void
    {
        config()->set('app.locale', 'zh_CN');
        config()->set('starlog.locale', 'en');
        app('translator')->setLocale('zh_CN');
        $this->refreshStarLog();

        $this->assertSame('GET[https://example.test] - Request:', app(StarLog::class)->translate('client.request', [
            'method' => 'GET',
            'url' => 'https://example.test',
        ]));
    }

    public function test_configured_application_locale_is_used_when_package_locale_is_null(): void
    {
        config()->set('app.locale', 'zh_CN');
        config()->set('starlog.locale', null);
        app('translator')->setLocale('en');
        $this->refreshStarLog();

        $this->assertSame('GET[https://example.test] - 请求报文:', app(StarLog::class)->translate('client.request', [
            'method' => 'GET',
            'url' => 'https://example.test',
        ]));
    }

    private function refreshStarLog(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
    }
}
