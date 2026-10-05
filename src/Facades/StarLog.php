<?php

namespace Caixingyue\LaravelStarLog\Facades;

use Caixingyue\LaravelStarLog\StarLog as StarLogImpl;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin StarLogImpl
 */
class StarLog extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return StarLogImpl::class;
    }
}
