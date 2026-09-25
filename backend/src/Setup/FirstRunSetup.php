<?php

namespace App\Setup;

use App\Entity\User;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * First-run setup: as long as there is no user at all, anyone reaching the instance can create the first
 * administrator (once). SETUP_TOKEN, when set, is required too: for instances reachable before they are set up.
 */
final class FirstRunSetup
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire(env: 'SETUP_TOKEN')] private readonly string $setupToken,
    ) {
    }

    public function isRequired(): bool
    {
        return 0 === (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM "user"');
    }

    public function isTokenRequired(): bool
    {
        return '' !== $this->setupToken;
    }

    public function createAdministrator(SetupInput $input): User
    {
        if ($this->isTokenRequired() && !hash_equals($this->setupToken, (string) $input->setupToken)) {
            throw new AccessDeniedHttpException('Invalid setup token.');
        }

        return $this->em->wrapInTransaction(function () use ($input): User {
            // Two concurrent setups: the second one waits here, then sees the first administrator.
            $this->em->getConnection()->executeStatement('LOCK TABLE "user" IN SHARE ROW EXCLUSIVE MODE');
            if (!$this->isRequired()) {
                throw new ConflictHttpException('Rocket Auth is already set up.');
            }

            $user = (new User())
                ->setEmail($input->email)
                ->setFirstName($input->firstName ?: null)
                ->setLastName($input->lastName ?: null)
                ->setRoles([Roles::ADMIN]);
            $user->setPassword($this->hasher->hashPassword($user, $input->password));
            $this->em->persist($user);
            $this->em->flush();

            return $user;
        });
    }
}
