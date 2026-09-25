<?php

namespace App\MessageHandler;

use App\Health\HealthChecker;
use App\Message\CheckServicesHealth;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckServicesHealthHandler
{
    public function __construct(private readonly HealthChecker $checker)
    {
    }

    public function __invoke(CheckServicesHealth $message): void
    {
        $this->checker->checkAll();
    }
}
