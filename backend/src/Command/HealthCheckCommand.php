<?php

namespace App\Command;

use App\Health\HealthChecker;
use App\Repository\ServiceCheckRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:health:check', description: 'Check the LDAP server and the sending mailboxes now (the worker also does it every 5 minutes).')]
final class HealthCheckCommand
{
    public function __construct(
        private readonly HealthChecker $checker,
        private readonly ServiceCheckRepository $checks,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->checker->checkAll()) {
            $io->warning('Checks are already running.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failing = false;
        foreach ($this->checks->findBy([], ['id' => 'ASC']) as $check) {
            $rows[] = [$check->getId(), $check->isOk() ? 'OK' : 'FAILING', $check->getDetail()];
            $failing = $failing || !$check->isOk();
        }
        $rows ? $io->table(['Check', 'Status', 'Detail'], $rows) : $io->note('Nothing to check: LDAP is off and no mailbox is enabled.');

        return $failing ? Command::FAILURE : Command::SUCCESS;
    }
}
