<?php

namespace App\Repository;

use App\Entity\Hotel;
use App\Entity\ImageAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ImageAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImageAsset::class);
    }

    public function findOneByUuid(string $uuid): ?ImageAsset
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<ImageAsset>
     */
    public function findByHotel(Hotel $hotel): array
    {
        return $this->createQueryBuilder('asset')
            ->andWhere('asset.hotel = :hotel')
            ->setParameter('hotel', $hotel)
            ->orderBy('asset.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
