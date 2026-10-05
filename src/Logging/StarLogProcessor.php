<?php

namespace Caixingyue\LaravelStarLog\Logging;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Capture execution information before the handler buffers or writes a record.
 */
final readonly class StarLogProcessor implements ProcessorInterface
{
    /**
     * Create a processor for the supplied formatter.
     */
    public function __construct(
        private StarLogFormatter $formatter,
    ) {}

    /**
     * Capture the formatter's execution fields while the original log call is still active.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $this->formatter->captureContext($record);
    }
}
