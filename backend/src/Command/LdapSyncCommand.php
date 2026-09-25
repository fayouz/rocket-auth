<?php

namespace App\Command;

use App\Ldap\LdapUserSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ldap:sync', description: 'Synchronize users from the LDAP directory (schedule it with cron).')]
final class LdapSyncCommand
{
    public function __construct(private readonly LdapUserSynchronizer $synchronizer)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Show what would change without writing')] bool $dryRun = false): int
    {
        $report = $this->synchronizer->sync($dryRun);

        $io->definitionList(
            ['Created' => $report->created],
            ['Updated' => $report->updated],
            ['Disabled' => $report->disabled],
            ['Local conflicts' => implode(', ', $report->conflicts) ?: '-'],
        );
        $io->success($dryRun ? 'Dry run: nothing was written.' : 'LDAP synchronization done.');

        return Command::SUCCESS;
    }
}
