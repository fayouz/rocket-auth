<?php

namespace App\Command;

use App\Update\UpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update:run',
    description: 'Run the update requested from the administration (servers without Docker; schedule it every minute).',
)]
final class UpdateRunCommand
{
    public function __construct(private readonly UpdateManager $updates)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $run = $this->updates->runPending(static function (string $output) use ($io): void {
            $io->write($output);
        });

        if (null === $run) {
            $io->writeln('No update requested.', SymfonyStyle::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }
        if ('succeeded' !== $run->getStatus()->value) {
            $io->error('The update failed: see the log in Administration → Mises à jour.');

            return Command::FAILURE;
        }
        $io->success('Update installed.');

        return Command::SUCCESS;
    }
}
