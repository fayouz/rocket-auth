<?php

namespace App\Repository;

use App\Entity\AuthenticationServer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function disableUsersForAuthenticationServer(AuthenticationServer $server): int
    {
        return $this->createQueryBuilder('user')
            ->update()
            ->set('user.enabled', ':enabled')
            ->where('user.authenticationServer = :server')
            ->setParameter('enabled', false)
            ->setParameter('server', $server)
            ->getQuery()
            ->execute();
    }
}
