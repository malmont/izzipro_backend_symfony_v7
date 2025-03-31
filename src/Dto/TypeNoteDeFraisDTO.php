<?php

namespace App\Dto;

use App\Entity\TypeNoteDeFrais;

class TypeNoteDeFraisDTO
{
    public int $id;
    public string $name;
    public ?string $image;

    public function __construct(int $id, string $name, ?string $image)
    {
        $this->id    = $id;
        $this->name  = $name;
        $this->image = $image;
    }

    public static function fromEntity(TypeNoteDeFrais $entity): self
    {
        return new self(
            $entity->getId(),
            $entity->getName(),
            $entity->getImage()
        );
    }
}
