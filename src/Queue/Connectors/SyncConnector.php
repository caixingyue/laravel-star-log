<?php

namespace Caixingyue\LaravelStarLog\Queue\Connectors;

use Caixingyue\LaravelStarLog\Queue\CorrelatedSyncQueue;
use Illuminate\Queue\Connectors\SyncConnector as FrameworkSyncConnector;

/**
 * @internal
 */
final class SyncConnector extends FrameworkSyncConnector
{
    /**
     * Create a correlated synchronous queue with the connection's after-commit setting.
     */
    public function connect(array $config): CorrelatedSyncQueue
    {
        return new CorrelatedSyncQueue($config['after_commit'] ?? null);
    }
}
