<?php

namespace App\Command;

use App\Entity\User;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a local user (e.g. the first administrator).')]
final class CreateUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] string $email,
        #[Argument] string $password,
        #[Option(description: 'Grant ROLE_ADMIN')] bool $admin = false,
    ): int {
        $user = (new User())->setEmail($email)->setPlainPassword($password);
        if ($admin) {
            $user->setRoles([Roles::ADMIN]);
        }

        $violations = $this->validator->validate($user, groups: ['Default', 'user:create']);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password))->setPlainPassword(null);
        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('User %s created.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
