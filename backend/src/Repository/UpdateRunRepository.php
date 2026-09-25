<?php

namespace App\Repository;

use App\Entity\UpdateRun;
use App\Enum\UpdateRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UpdateRun> */
class UpdateRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpdateRun::class);
    }

    public function latest(): ?UpdateRun
    {
        return $this->findOneBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** Oldest update waiting for the scheduled task. */
    public function nextRequested(): ?UpdateRun
    {
        return $this->findOneBy(['status' => UpdateRunStatus::Requested], ['createdAt' => 'ASC']);
    }

    /** @return list<UpdateRun> */
    public function recent(int $limit = 10): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
