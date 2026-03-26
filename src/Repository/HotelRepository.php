<?php

namespace App\Repository;

use App\Entity\Hotel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HotelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Hotel::class);
    }

    public function findOneByExternalHotelId(string $externalHotelId): ?Hotel
    {
        return $this->findOneBy(['externalHotelId' => $externalHotelId]);
    }

    /**
     * @param list<string> $externalHotelIds
     *
     * @return list<Hotel>
     */
    public function findByExternalHotelIds(array $externalHotelIds): array
    {
        if ($externalHotelIds === []) {
            return [];
        }

        return $this->createQueryBuilder('hotel')
            ->andWhere('hotel.externalHotelId IN (:externalHotelIds)')
            ->setParameter('externalHotelIds', $externalHotelIds)
            ->getQuery()
            ->getResult();
    }
}
