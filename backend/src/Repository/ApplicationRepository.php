<?php

namespace App\Repository;

use App\Entity\Application;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Application> */
class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Application::class);
    }

    public function findOneByToken(string $token): ?Application
    {
        if (!str_starts_with($token, Application::TOKEN_PREFIX)) {
            return null;
        }

        return $this->findOneBy(['tokenHash' => Application::hashToken($token)]);
    }

    /** Records usage without going through the ORM, so it does not bump updatedAt/updatedBy. */
    public function touch(Application $application): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE '.Application::class.' a SET a.lastUsedAt = :now WHERE a.id = :id')
            ->setParameter('now', new \DateTimeImmutable(), 'datetime_immutable')
            ->setParameter('id', $application->getId(), UuidType::NAME)
            ->execute();
    }
}
