<?php

namespace Caixingyue\LaravelStarLog\Facades;

use Caixingyue\LaravelStarLog\StarLog as StarLogService;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin StarLogService
 */
class StarLog extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return StarLogService::class;
    }
}
