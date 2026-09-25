<?php

namespace App\Command;

use App\Entity\User;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Demo data of a domain module, loaded by app:demo:seed after the users (must be idempotent). */
#[AutoconfigureTag('app.demo_seeder')]
interface DemoSeederInterface
{
    /** @param array<string, User> $users demo users by email */
    public function seed(array $users, SymfonyStyle $io): void;
}
