<?php

namespace Caixingyue\LaravelStarLog\Logging;

/**
 * Preserve execution information captured before a log record is buffered.
 */
final readonly class LogContextSnapshot
{
    /**
     * Store the captured log context.
     */
    public function __construct(
        public ?string $correlation,
        public ?string $callSite,
        public ?string $ips,
    ) {}
}
