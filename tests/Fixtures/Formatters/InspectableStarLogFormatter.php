<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Formatters;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;

final class InspectableStarLogFormatter extends StarLogFormatter
{
    public function requestIps(): ?string
    {
        return $this->formatRequestIps();
    }
}
