<?php

namespace App\Connector;

final class ExternalHotelAccess
{
    public function __construct(
        private string $externalHotelId,
        private ?string $name = null,
        private array $attributes = []
    ) {
    }

    public function getExternalHotelId(): string
    {
        return $this->externalHotelId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDisplayName(): string
    {
        return $this->name ?? $this->externalHotelId;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function toArray(): array
    {
        return [
            'externalHotelId' => $this->externalHotelId,
            'name' => $this->name,
            'attributes' => $this->attributes,
        ];
    }
}
