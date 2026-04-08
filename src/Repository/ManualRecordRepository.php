<?php

namespace App\Repository;

use App\Entity\ManualRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ManualRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManualRecord::class);
    }

    /**
     * @return list<ManualRecord>
     */
    public function findActiveRecords(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('record')
            ->andWhere('record.validUntil > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
