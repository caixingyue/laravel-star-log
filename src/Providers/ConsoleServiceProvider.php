<?php

namespace Caixingyue\LaravelStarLog\Providers;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Correlation\ExceptionLogContext;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ReflectionProperty;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Parent contexts and pending exceptions for active Artisan commands.
     *
     * @var array<int, array{input: InputInterface, chain: array, exception?: ConsoleErrorEvent, exceptionChain?: array}>
     */
    private array $correlationChainStack = [];

    /**
     * Register the Artisan command lifecycle listeners.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->correlationChainStack[] = [
                'input' => $event->input,
                'chain' => StarLog::getCorrelationChain(),
            ];

            $this->app->make(QueryLogState::class)->beginExecution();

            $command = $this->app->make(Kernel::class)->all()[$event->command] ?? null;

            if ($command instanceof ShouldBeCorrelated) {
                StarLog::appendArtisanCorrelation($event->command);

                return;
            }

            StarLog::setCorrelationChain([]);
        });

        Event::listen(CommandFinished::class, fn (CommandFinished $event) => $this->restoreExecutionContext($event));

        $this->registerExceptionLogContext();
    }

    /**
     * Observe command errors on Laravel's existing Symfony event dispatcher.
     */
    private function registerExceptionLogContext(): void
    {
        $this->callAfterResolving(Kernel::class, function (Kernel $kernel): void {
            $this->app->booted(function () use ($kernel): void {
                if (! $kernel instanceof FoundationKernel || ! property_exists($kernel, 'symfonyDispatcher')) {
                    return;
                }

                // Laravel exposes no dispatcher accessor; only read its existing dispatcher.
                $dispatcher = (new ReflectionProperty($kernel, 'symfonyDispatcher'))->getValue($kernel);

                if (! $dispatcher instanceof EventDispatcherInterface) {
                    return;
                }

                $dispatcher->addListener(ConsoleEvents::ERROR, function (ConsoleErrorEvent $event): void {
                    $index = array_key_last($this->correlationChainStack);

                    if ($index === null || $this->correlationChainStack[$index]['input'] !== $event->getInput()) {
                        return;
                    }

                    $chain = StarLog::getCorrelationChain();
                    $this->correlationChainStack[$index]['exception'] = $event;
                    $this->correlationChainStack[$index]['exceptionChain'] = $chain;
                    $this->app->make(ExceptionLogContext::class)->remember($event->getError(), $chain);
                }, PHP_INT_MAX);
            });
        });
    }

    /**
     * Restore the parent correlation chain and SQL entry count after the command finishes.
     *
     * @throws BindingResolutionException
     */
    private function restoreExecutionContext(CommandFinished $event): void
    {
        $context = $this->correlationChainStack[array_key_last($this->correlationChainStack)] ?? null;

        if ($context === null || $context['input'] !== $event->input) {
            return;
        }

        array_pop($this->correlationChainStack);

        if (isset($context['exception'])) {
            // Error listeners may replace the exception or stop further error listeners.
            $this->app->make(ExceptionLogContext::class)->remember($context['exception']->getError(), $context['exceptionChain']);
        }

        StarLog::setCorrelationChain($context['chain']);
        $this->app->make(QueryLogState::class)->finishExecution();
    }
}
