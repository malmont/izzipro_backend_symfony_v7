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

        $imagePath = $entity->getImagePath();
        if (str_starts_with((string)$imagePath, 'http')) {
            $image = $imagePath;
        } else if ($imagePath) {
            $cleanedHost = rtrim($host, '/');
            $image = $cleanedHost . '/assets/uploads/explore/' . $imagePath;
        } else {
            $image = null;
        }
        $dto->imageUrl    = $image;
        
        $videoPath = $entity->getVideoPath();
        if (str_starts_with((string)$videoPath, 'http')) {
            $video = $videoPath;
        } else if ($videoPath) {
            $cleanedHost = rtrim($host, '/');
            $cleanVideoPath = ltrim($videoPath, '/');
            if (str_starts_with($cleanVideoPath, 'assets/')) {
                $video = $cleanedHost . '/' . $cleanVideoPath;
            } else {
                $video = $cleanedHost . '/assets/uploads/explore/' . $cleanVideoPath;
            }
        } else {
            $video = null;
        }
        $dto->videoUrl    = $video;

        $dto->standardTitle  = $translation?->getStandardTitle() ?? $entity->getStandardTitle();
        $dto->differentTitle = $translation?->getDifferentTitle() ?? $entity->getDifferentTitle();
        $dto->description    = $translation?->getDescription() ?? $entity->getDescription();
        
        return $dto;
    }
}