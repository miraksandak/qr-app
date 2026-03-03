<?php

namespace App\Entity;

use App\Repository\ManualRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ManualRecordRepository::class)]
#[ORM\Table(name: 'manual_records')]
class ManualRecord
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 5)]
    private string $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $payloadJson;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $payloadJson,
        \DateTimeImmutable $validUntil,
        \DateTimeImmutable $createdAt
    ) {
        $this->id = $id;
        $this->payloadJson = $payloadJson;
        $this->validUntil = $validUntil;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayloadJson(): string
    {
        return $this->payloadJson;
    }

    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
