<?php

namespace Caixingyue\LaravelStarLog\Queue;

use Caixingyue\LaravelStarLog\Facades\StarLog;

trait InjectionId
{
    public ?int $queueId = null;

    public array $starLogIds = [];

    public function __get(string $name)
    {
        $this->initializeInjectionId();
    }

    public function initializeInjectionId(): void
    {
        if (!$this->queueId) {
            $this->queueId = StarLog::appendQueueTaskId($this);
        }

        $this->starLogIds = StarLog::getInjectionIds();
    }

    public function getId(): ?int
    {
        return $this->queueId;
    }

    public function getRequestId(): ?int
    {
        return StarLog::getRequestId();
    }

    public function getArtisanId(): ?int
    {
        return StarLog::getArtisanId();
    }

    public function getQueueId(): ?int
    {
        return StarLog::getQueueId($this);
    }
}
