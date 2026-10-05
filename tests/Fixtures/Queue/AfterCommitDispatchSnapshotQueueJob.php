<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

final class AfterCommitDispatchSnapshotQueueJob extends DispatchSnapshotQueueJob implements ShouldQueueAfterCommit {}
