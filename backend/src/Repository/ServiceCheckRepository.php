<?php

namespace App\Repository;

use App\Entity\ServiceCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceCheck> */
class ServiceCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCheck::class);
    }

    /** @return array<string, ServiceCheck> indexed by id */
    public function allById(): array
    {
        $checks = [];
        foreach ($this->findAll() as $check) {
            $checks[$check->getId()] = $check;
        }

        return $checks;
    }
}
