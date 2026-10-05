<?php

namespace Caixingyue\LaravelStarLog\Tests\Concerns;

use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Concerns\SqlLoggingColumnModel;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class HasQueryLogBindingColumnsTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_model_initialization_registers_binding_settings_for_its_table(): void
    {
        $container = new Container;
        $registry = new QueryBindingColumnRegistry;

        $container->instance(QueryBindingColumnRegistry::class, $registry);
        Container::setInstance($container);

        new SqlLoggingColumnModel;

        $this->assertSame([
            'password' => ['sensitive' => true],
        ], $registry->forTable('sql_logging_column_models'));
    }
}
