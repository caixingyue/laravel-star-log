<?php

namespace Caixingyue\LaravelStarLog\Console;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait InjectionId
{
    public ?int $artisanId = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeInjectionId();
        return parent::execute($input, $output);
    }

    public function initializeInjectionId(): void
    {
        if (!$this->artisanId) {
            $this->artisanId = StarLog::appendArtisanTaskId($this);
        }
    }

    public function getId(): ?int
    {
        return $this->artisanId;
    }

    public function getRequestId(): ?int
    {
        return StarLog::getRequestId();
    }

    public function getArtisanId(): ?int
    {
        return StarLog::getArtisanId($this);
    }

    public function getQueueId(): ?int
    {
        return StarLog::getQueueId();
    }
}
