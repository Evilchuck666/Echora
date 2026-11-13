<?php

namespace App\Repository;

use App\Entity\LibraryScan;
use App\Library\ScanStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LibraryScan>
 */
class LibraryScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryScan::class);
    }

    /**
     * The most recent scan, whatever its status (ids are UUIDv7: they sort by time).
     */
    public function findLatest(): ?LibraryScan
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }

    /**
     * @return list<LibraryScan> The scans queued or running
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => [ScanStatus::Queued, ScanStatus::Running]], ['id' => 'ASC']);
    }
}
