<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\ShouldBeUnique;

final class UniqueDispatchSnapshotQueueJob extends DispatchSnapshotQueueJob implements ShouldBeUnique
{
    public function uniqueId(): string
    {
        return $this->value;
    }
}
