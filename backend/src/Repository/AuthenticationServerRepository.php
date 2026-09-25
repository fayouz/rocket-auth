<?php

namespace App\Repository;

use App\Entity\AuthenticationServer;
use App\Enum\AuthenticationServerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuthenticationServer> */
class AuthenticationServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthenticationServer::class);
    }

    public function findLdap(): ?AuthenticationServer
    {
        return $this->findOneBy(['type' => AuthenticationServerType::Ldap], ['createdAt' => 'ASC']);
    }

    /** @return list<AuthenticationServer> */
    public function findEnabledOidc(): array
    {
        return $this->findBy(['type' => AuthenticationServerType::Oidc, 'enabled' => true], ['name' => 'ASC']);
    }
}
