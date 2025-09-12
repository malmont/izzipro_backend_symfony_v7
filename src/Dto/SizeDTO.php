<?php
namespace App\Dto;

use App\Entity\Size;

class SizeDTO
{
    public int $id;
    public ?string $code;
    public ?string $name;

    private function __construct() {}

    public static function fromEntity(Size $size, string $locale): self
    {
        $dto = new self();
        
        // --- Logique "en attente" ---
        // $translation = $size->getTranslation($locale);
        // TODO: Après la migration, on branchera cette logique.

        $dto->id   = $size->getId();
        $dto->code = $size->getCode();
        $dto->name = $size->getName();
        
        return $dto;
    }
}