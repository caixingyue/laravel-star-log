<?php

namespace Caixingyue\LaravelStarLog\Concerns;

use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;

trait HasQueryLogBindingColumns
{
    /**
     * Register this model's binding column log settings when the model is initialized.
     */
    public function initializeHasQueryLogBindingColumns(): void
    {
        $columns = static::queryLogBindingColumns();

        app(QueryBindingColumnRegistry::class)->register($this->getTable(), $columns);
    }

    /**
     * Define masking and length settings for SQL binding values by database column.
     *
     * This method runs when a model instance is initialized. Its result must not
     * depend on the current request, authentication, tenant, or service state.
     *
     * @return array<string, array{sensitive?: bool, max_length?: int|null}>
     */
    protected static function queryLogBindingColumns(): array
    {
        return [];
    }
}
