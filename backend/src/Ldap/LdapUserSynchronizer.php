<?php

namespace App\Ldap;

use App\Entity\User;
use App\Repository\AuthenticationServerRepository;
use App\Enum\UserSource;
use App\Repository\UserRepository;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final class LdapUserSynchronizer
{
    public function __construct(
        private readonly UserDirectoryInterface $directory,
        private readonly UserRepository $users,
        private readonly AuthenticationServerRepository $servers,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function sync(bool $dryRun = false): SyncReport
    {
        if (!$this->directory->isEnabled()) {
            throw new \LogicException('LDAP is disabled (LDAP_ENABLED=0).');
        }

        $lock = $this->lockFactory->createLock('ldap-user-sync', 600);
        if (!$lock->acquire()) {
            throw new \RuntimeException('An LDAP synchronization is already running.');
        }

        try {
            return $this->doSync($dryRun);
        } finally {
            $lock->release();
        }
    }

    private function doSync(bool $dryRun): SyncReport
    {
        $report = new SyncReport($dryRun);
        $now = new \DateTimeImmutable();
        $authenticationServer = $this->servers->findLdap();

        $byDn = [];
        $byEmail = [];
        foreach ($this->users->findAll() as $user) {
            $byEmail[$user->getEmail()] = $user;
            if (UserSource::Ldap === $user->getSource() && null !== $user->getLdapDn()) {
                $byDn[mb_strtolower($user->getLdapDn())] = $user;
            }
        }

        $seen = [];
        foreach ($this->directory->fetchUsers() as $entry) {
            $email = mb_strtolower(trim($entry->email));
            $user = $byDn[mb_strtolower($entry->dn)] ?? $byEmail[$email] ?? null;

            // Never let the directory take over an existing local account (e.g. the bootstrap admin).
            if (null !== $user && UserSource::Local === $user->getSource()) {
                $report->conflicts[] = $email;
                continue;
            }

            if (null === $user) {
                $user = (new User())->setSource(UserSource::Ldap);
                $this->em->persist($user);
                ++$report->created;
            } else {
                ++$report->updated;
            }

            $roles = $user->getRoles();
            if (null !== $entry->admin) {
                $roles = array_values(array_diff($roles, [Roles::ADMIN]));
                if ($entry->admin) {
                    $roles[] = Roles::ADMIN;
                }
            }

            $user->setEmail($email)
                ->setLdapDn($entry->dn)
                ->setFirstName($entry->firstName)
                ->setLastName($entry->lastName)
                ->setRoles($roles)
                ->setPassword(null)
                ->setEnabled(true)
                ->setAuthenticationServer($authenticationServer)
                ->setLdapSyncedAt($now);

            $seen[spl_object_id($user)] = true;
        }

        foreach ($byDn as $user) {
            if (!isset($seen[spl_object_id($user)]) && $user->isEnabled()) {
                $user->setEnabled(false);
                ++$report->disabled;
            }
        }

        if ($dryRun) {
            $this->em->clear();
        } else {
            $this->em->flush();
        }

        return $report;
    }
}
