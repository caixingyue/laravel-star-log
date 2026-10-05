<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Concerns;

use Caixingyue\LaravelStarLog\Concerns\HasQueryLogBindingColumns;
use Illuminate\Database\Eloquent\Model;

final class SqlLoggingColumnModel extends Model
{
    use HasQueryLogBindingColumns;

    protected static function queryLogBindingColumns(): array
    {
        return [
            'password' => ['sensitive' => true],
        ];
    }
}
