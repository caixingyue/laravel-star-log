<?php

namespace Caixingyue\LaravelStarLog\Tests\Query;

use Caixingyue\LaravelStarLog\Query\QueryLogState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueryLogStateTest extends TestCase
{
    public function test_table_ignores_are_reference_counted(): void
    {
        $state = new QueryLogState;

        $state->ignoreTable('users');
        $state->ignoreTable('users');
        $state->resumeTable('users');

        $this->assertTrue($state->isTableIgnored('users'));
        $this->assertTrue($state->isTableIgnored('tenant.users'));

        $state->resumeTable('users');

        $this->assertFalse($state->isTableIgnored('users'));
    }

    public function test_temporary_table_ignores_are_restored_after_a_callback_failure(): void
    {
        $state = new QueryLogState;

        try {
            $state->withoutTables(['audit_logs'], function (): void {
                throw new RuntimeException('expected');
            });
        } catch (RuntimeException) {
            // Expected test exception.
        }

        $this->assertFalse($state->isTableIgnored('audit_logs'));
    }

    public function test_logged_entry_limits_are_checked_against_the_current_execution_count(): void
    {
        $state = new QueryLogState;

        $this->assertFalse($state->hasReachedEntryLimit(1));
        $state->incrementEntryCount();
        $this->assertTrue($state->hasReachedEntryLimit(1));
        $this->assertFalse($state->hasReachedEntryLimit(null));
    }

    public function test_nested_logging_pauses_are_restored_after_a_callback_failure(): void
    {
        $state = new QueryLogState;

        try {
            $state->withoutLogging(function () use ($state): void {
                $this->assertTrue($state->isLoggingPaused());

                $state->withoutLogging(function () use ($state): void {
                    $this->assertTrue($state->isLoggingPaused());
                });

                throw new RuntimeException('expected');
            });
        } catch (RuntimeException) {
            // Expected test exception.
        }

        $this->assertFalse($state->isLoggingPaused());
    }
}
