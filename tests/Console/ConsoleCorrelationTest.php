<?php

namespace Caixingyue\LaravelStarLog\Tests\Console;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\ChildCorrelatedCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\CommandDispatchedQueueJob;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\CorrelatedCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\FailingCorrelatedCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\ParentCorrelatedCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\QueueInvokedCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Console\UncorrelatedCommand;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class ConsoleCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CorrelatedCommand::$executions = [];
        FailingCorrelatedCommand::$executions = [];
        ParentCorrelatedCommand::$executions = [];
        ParentCorrelatedCommand::$dispatchQueueAfterChild = false;
        ChildCorrelatedCommand::$executions = [];
        ChildCorrelatedCommand::$dispatchQueue = false;
        CommandDispatchedQueueJob::$executions = [];
        CommandDispatchedQueueJob::$callArtisan = false;
        QueueInvokedCommand::$executions = [];
        UncorrelatedCommand::$executions = [];
        ParentCorrelatedCommand::$callUnmarkedCommand = false;
        config()->set('queue.default', 'sync');

        /** @var \Illuminate\Foundation\Console\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new CorrelatedCommand);
        $kernel->registerCommand(new FailingCorrelatedCommand);
        $kernel->registerCommand(new ParentCorrelatedCommand);
        $kernel->registerCommand(new ChildCorrelatedCommand);
        $kernel->registerCommand(new QueueInvokedCommand);
        $kernel->registerCommand(new UncorrelatedCommand);
    }

    public function test_correlated_commands_receive_distinct_ids_and_restore_the_caller_chain(): void
    {
        $request = ['id' => 101, 'name' => 'request', 'type' => 'request'];
        StarLog::setCorrelationChain([$request]);

        Artisan::call('star-log:correlation-test');
        Artisan::call('star-log:correlation-test');

        [$first, $second] = CorrelatedCommand::$executions;

        $this->assertSame($request, $first[0]);
        $this->assertSame('artisan', $first[1]['type']);
        $this->assertNotSame($first[1]['id'], $second[1]['id']);
        $this->assertSame([$request], StarLog::getCorrelationChain());
    }

    public function test_nested_correlated_commands_keep_parent_and_current_artisan_ids_distinct(): void
    {
        Artisan::call('star-log:parent-correlation-test');

        [$beforeChild, $afterChild] = ParentCorrelatedCommand::$executions;
        $child = ChildCorrelatedCommand::$executions[0];

        $this->assertSame($beforeChild['id'], $afterChild['id']);
        $this->assertSame($beforeChild['chain'], $afterChild['chain']);
        $this->assertSame($beforeChild['id'], $child['chain'][0]['id']);
        $this->assertSame($child['id'], $child['chain'][1]['id']);
        $this->assertSame($child['id'], $child['artisanId']);
        $this->assertSame($beforeChild['id'], $child['nearestArtisanId']);
    }

    public function test_failing_command_restores_the_caller_chain(): void
    {
        $request = ['id' => 101, 'name' => 'request', 'type' => 'request'];
        StarLog::setCorrelationChain([$request]);

        try {
            Artisan::call('star-log:failing-correlation-test');
            $this->fail('The command exception must reach its caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Command failed.', $exception->getMessage());
        }
        $this->assertSame($request, FailingCorrelatedCommand::$executions[0][0]);
        $this->assertSame('artisan', FailingCorrelatedCommand::$executions[0][1]['type']);
        $this->assertSame([$request], StarLog::getCorrelationChain());
    }

    public function test_queue_dispatched_by_a_nested_command_uses_the_dispatching_command_as_its_parent(): void
    {
        ChildCorrelatedCommand::$dispatchQueue = true;

        Artisan::call('star-log:parent-correlation-test');

        $child = ChildCorrelatedCommand::$executions[0];
        $queue = CommandDispatchedQueueJob::$executions[0];

        $this->assertSame($child['id'], $queue['artisanId']);
        $this->assertSame($child['id'], $queue['nearestArtisanId']);
        $this->assertSame($queue['id'], $queue['queueId']);
        $this->assertSame($child['id'], $queue['parent']['id']);
        $this->assertSame('artisan', $queue['parent']['type']);
        $this->assertSame('queue', $queue['chain'][2]['type']);
    }

    public function test_queue_dispatched_after_a_nested_command_uses_the_restored_parent_command(): void
    {
        ParentCorrelatedCommand::$dispatchQueueAfterChild = true;

        Artisan::call('star-log:parent-correlation-test');

        $parent = ParentCorrelatedCommand::$executions[0];
        $queue = CommandDispatchedQueueJob::$executions[0];

        $this->assertSame($parent['id'], $queue['artisanId']);
        $this->assertSame($parent['id'], $queue['nearestArtisanId']);
        $this->assertSame($parent['id'], $queue['parent']['id']);
        $this->assertSame('artisan', $queue['parent']['type']);
        $this->assertCount(2, $queue['chain']);
    }

    public function test_command_started_by_a_queue_reads_its_queue_parent(): void
    {
        CommandDispatchedQueueJob::$callArtisan = true;

        dispatch(new CommandDispatchedQueueJob);

        $queue = CommandDispatchedQueueJob::$executions[0];
        $artisan = QueueInvokedCommand::$executions[0];

        $this->assertSame($queue['id'], $artisan['queueId']);
        $this->assertSame($queue['chain'][0]['id'], $artisan['nearestQueueId']);
        $this->assertSame($queue['chain'][0], $artisan['parent']);
        $this->assertSame('artisan', $artisan['chain'][1]['type']);
    }

    public function test_uncorrelated_command_starts_without_a_correlation_chain(): void
    {
        Artisan::call('star-log:unmarked-test');

        $this->assertSame([], UncorrelatedCommand::$executions[0]);
        $this->assertSame([], StarLog::getCorrelationChain());
    }

    public function test_uncorrelated_command_breaks_the_chain_but_restores_its_correlated_parent(): void
    {
        ParentCorrelatedCommand::$callUnmarkedCommand = true;

        Artisan::call('star-log:parent-correlation-test');

        [$before, $after] = ParentCorrelatedCommand::$executions;
        [$during, $afterChild] = UncorrelatedCommand::$executions;
        $child = ChildCorrelatedCommand::$executions[0];

        $this->assertSame($before, $after);
        $this->assertSame([], $during);
        $this->assertSame([], $afterChild);
        $this->assertCount(1, $child['chain']);
        $this->assertSame($child['id'], $child['chain'][0]['id']);
    }
}
