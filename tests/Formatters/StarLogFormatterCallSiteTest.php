<?php

namespace Caixingyue\LaravelStarLog\Tests\Formatters;

use Caixingyue\LaravelStarLog\Logging\AttachStarLogProcessor;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Formatters\CallSiteTestFormatter;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StarLogFormatterCallSiteTest extends TestCase
{
    private Application $app;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'laravel-star-log-call-site-');
        $this->app = new Application(__DIR__);
        $this->app->instance('config', new Config([
            'logging' => [
                'default' => 'call-site',
                'channels' => [
                    'call-site' => [
                        'driver' => 'single',
                        'path' => $this->logPath,
                        'formatter' => CallSiteTestFormatter::class,
                    ],
                ],
            ],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->singleton('log', fn (Application $app): LogManager => new LogManager($app));
        $this->app->bind(LoggerInterface::class, fn (): LoggerInterface => app('log')->channel('call-site'));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        @unlink($this->logPath);

        parent::tearDown();
    }

    public static function snapshotModes(): array
    {
        return ['formatter only' => [false], 'processor' => [true]];
    }

    #[DataProvider('snapshotModes')]
    public function test_resolves_call_sites_for_laravels_standard_logging_entry_points(bool $capture): void
    {
        if ($capture) {
            $this->app['config']->set('logging.channels.call-site.tap', [AttachStarLogProcessor::class]);
        }

        $this->writeThroughFacade();
        $this->writeThroughManager();
        $this->writeThroughLoggerHelper();
        $this->writeThroughFacadeChannel();
        $this->writeThroughHelperChannel();
        $this->writeThroughLoggerContract();
        $this->writeThroughFacadeLogMethod();
        $this->writeThroughManagerLogMethod();
        $this->writeThroughLoggerLogMethod();
        $this->writeThroughLoggerWriteMethod();
        $this->writeThroughStack();
        $this->writeThroughMonolog();
        $this->writeThroughClosure();

        $output = file_get_contents($this->logPath);

        $this->assertIsString($output);

        $testClass = str_replace('\\', '.', self::class);

        foreach ([
            'facade' => 'writeThroughFacade',
            'manager' => 'writeThroughManager',
            'logger helper' => 'writeThroughLoggerHelper',
            'facade channel' => 'writeThroughFacadeChannel',
            'helper channel' => 'writeThroughHelperChannel',
            'logger contract' => 'writeThroughLoggerContract',
            'facade log' => 'writeThroughFacadeLogMethod',
            'manager log' => 'writeThroughManagerLogMethod',
            'logger log' => 'writeThroughLoggerLogMethod',
            'logger write' => 'writeThroughLoggerWriteMethod',
            'stack' => 'writeThroughStack',
            'monolog' => 'writeThroughMonolog',
        ] as $message => $method) {
            $this->assertMatchesRegularExpression(
                '/\[' . preg_quote($testClass, '/') . '@' . preg_quote($method, '/') . ':\d+\]: ' . preg_quote($message, '/') . '/',
                $output
            );
        }

        $this->assertMatchesRegularExpression(
            '/\[' . preg_quote($testClass, '/') . '@closure:\d+\]: closure/',
            $output
        );
    }

    private function writeThroughFacade(): void
    {
        Log::info('facade');
    }

    private function writeThroughManager(): void
    {
        logger()->info('manager');
    }

    private function writeThroughLoggerHelper(): void
    {
        logger('logger helper');
    }

    private function writeThroughFacadeChannel(): void
    {
        Log::channel('call-site')->info('facade channel');
    }

    private function writeThroughHelperChannel(): void
    {
        /** @var LogManager $manager */
        $manager = logger();
        $manager->channel('call-site')->info('helper channel');
    }

    private function writeThroughLoggerContract(): void
    {
        app(LoggerInterface::class)->info('logger contract');
    }

    private function writeThroughFacadeLogMethod(): void
    {
        Log::log('info', 'facade log');
    }

    private function writeThroughManagerLogMethod(): void
    {
        logger()->log('info', 'manager log');
    }

    private function writeThroughLoggerLogMethod(): void
    {
        /** @var LogManager $manager */
        $manager = logger();
        $manager->channel('call-site')->log('info', 'logger log');
    }

    private function writeThroughLoggerWriteMethod(): void
    {
        /** @var LogManager $manager */
        $manager = logger();
        $manager->channel('call-site')->write('info', 'logger write');
    }

    private function writeThroughStack(): void
    {
        Log::stack(['call-site'])->info('stack');
    }

    private function writeThroughMonolog(): void
    {
        $handler = new StreamHandler($this->logPath);
        $handler->setFormatter(new CallSiteTestFormatter);

        (new MonologLogger('call-site', [$handler]))->info('monolog');
    }

    private function writeThroughClosure(): void
    {
        (function (): void {
            logger()->info('closure');
        })();
    }
}
