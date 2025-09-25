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
        $translation = $entity->getTranslation($locale);

        $dto->id          = $entity->getId();
        $dto->isDifferent = $entity->getIsDifferent();
        $dto->link        = $entity->getLink();
        
        $basePath = rtrim($host, '/') . '/assets/uploads/explore/';
        $dto->imageUrl    = $entity->getImagePath() ? $basePath . $entity->getImagePath() : null;
        $dto->videoUrl    = $entity->getVideoPath();

        $dto->standardTitle  = $translation?->getStandardTitle() ?? $entity->getStandardTitle();
        $dto->differentTitle = $translation?->getDifferentTitle() ?? $entity->getDifferentTitle();
        $dto->description    = $translation?->getDescription() ?? $entity->getDescription();
        
        return $dto;
    }
}