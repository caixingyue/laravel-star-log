<?php

namespace Caixingyue\LaravelStarLog\Logging;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;
use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;
use WeakMap;

/**
 * Enable snapshot capture on a Laravel channel's Star Log handlers.
 */
final class AttachStarLogProcessor
{
    /**
     * @var WeakMap<object, true>|null Shared handlers must receive the processor only once.
     */
    private static ?WeakMap $registeredHandlers = null;

    /**
     * Attach snapshot capture once to each supported top-level handler using StarLogFormatter.
     */
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        self::$registeredHandlers ??= new WeakMap;

        foreach ($monolog->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface
                || ! $handler instanceof ProcessableHandlerInterface
                || isset(self::$registeredHandlers[$handler])) {
                continue;
            }

            $formatter = $handler->getFormatter();

            if ($formatter instanceof StarLogFormatter) {
                $handler->pushProcessor(new StarLogProcessor($formatter));
                self::$registeredHandlers[$handler] = true;
            }
        }
    }
}
