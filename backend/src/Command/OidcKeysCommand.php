<?php

namespace App\Command;

use App\OAuth\SigningKeys;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:oidc:generate-key', description: 'Generate the RSA key signing the OpenID Connect tokens (kept when it exists, unless --rotate).')]
final class OidcKeysCommand
{
    public function __construct(private readonly SigningKeys $keys)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Replace the existing key: tokens signed with it become invalid.')] bool $rotate = false): int
    {
        if (!$this->keys->generate($rotate)) {
            $io->note('A signing key already exists (use --rotate to replace it).');

            return Command::SUCCESS;
        }
        $io->success(\sprintf('Signing key generated (kid %s).', $this->keys->kid()));

        return Command::SUCCESS;
    }
}
