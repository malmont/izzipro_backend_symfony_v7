<?php
// src/Dto/ExploreCardDto.php
namespace App\Dto;

use App\Entity\ExploreCard;

class ExploreCardDto
{
    public int     $id;
    public bool    $isDifferent;
    public ?string $standardTitle;
    public ?string $differentTitle;
    public ?string $description;
    public ?string $link;
    public ?string $imageUrl;
    public ?string $videoUrl;

    private function __construct() {}

    public static function fromEntity(ExploreCard $entity, string $host, string $locale): self
    {
        $dto = new self();
        
        $dto->id          = $entity->getId();
        $dto->isDifferent = $entity->getIsDifferent();
        $dto->link        = $entity->getLink();
        

        $basePath = rtrim($host, '/') . '/assets/uploads/explore/';
        $dto->imageUrl    = $entity->getImagePath() ? $basePath . $entity->getImagePath() : null;
        $dto->videoUrl    = $entity->getVideoPath();

        // $translation = $entity->getTranslation($locale);
        // TODO: Après la migration, brancher la logique de traduction ici.

        $dto->standardTitle  = $entity->getStandardTitle();
        $dto->differentTitle = $entity->getDifferentTitle();
        $dto->description    = $entity->getDescription();
        
        return $dto;
    }
}