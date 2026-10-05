<?php

namespace Caixingyue\LaravelStarLog\Concerns;

use Caixingyue\LaravelStarLog\Facades\StarLog;

trait InteractsWithCorrelation
{
    /**
     * Get the current execution ID using the short accessor.
     */
    public function getId(): ?int
    {
        return $this->getCurrentCorrelationId();
    }

    /**
     * Get the ID for the currently executing correlated unit.
     */
    public function getCurrentCorrelationId(): ?int
    {
        return StarLog::getCurrentCorrelation()['id'] ?? null;
    }

    /**
     * Get the request ID in the active correlation chain.
     */
    public function getRequestId(): ?int
    {
        return StarLog::getRequestId();
    }

    /**
     * Get the current or nearest preceding Artisan command ID.
     */
    public function getArtisanId(): ?int
    {
        return StarLog::getArtisanId();
    }

    /**
     * Get the current or nearest preceding queue job ID.
     */
    public function getQueueId(): ?int
    {
        return StarLog::getQueueId();
    }

    /**
     * Get the nearest Artisan ID before the current execution unit.
     */
    public function getNearestArtisanId(): ?int
    {
        return StarLog::getNearestArtisanId();
    }

    /**
     * Get the nearest queue ID before the current execution unit.
     */
    public function getNearestQueueId(): ?int
    {
        return StarLog::getNearestQueueId();
    }

    /**
     * Get the execution unit that directly invoked the current unit.
     */
    public function getParentCorrelation(): ?array
    {
        return StarLog::getParentCorrelation();
    }

    /**
     * Get the complete request, Artisan, and queue correlation chain.
     */
    public function getCorrelationChain(): array
    {
        return StarLog::getCorrelationChain();
    }
}
