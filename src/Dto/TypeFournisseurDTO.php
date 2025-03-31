<?php

namespace App\Dto;

use App\Entity\TypeFournisseur;

class TypeFournisseurDTO
{
    public int $id;
    public string $name;
    public ?string $photo;

    public function __construct(int $id, string $name, ?string $photo)
    {
        $this->id    = $id;
        $this->name  = $name;
        $this->photo = $photo;
    }

    public static function fromEntity(TypeFournisseur $typeFournisseur): self
    {
        return new self(
            $typeFournisseur->getId(),
            $typeFournisseur->getName(),
            $typeFournisseur->getPhoto()
        );
    }
}
