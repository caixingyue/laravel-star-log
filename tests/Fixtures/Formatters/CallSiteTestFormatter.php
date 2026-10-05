<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Formatters;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;

final class CallSiteTestFormatter extends StarLogFormatter
{
    protected function formatCorrelation(): ?string
    {
        return null;
    }
}
