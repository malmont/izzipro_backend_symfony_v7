<?php
// src/Dto/ExploreCardDto.php
namespace App\Dto;

use App\Entity\ExploreCard;
use App\Services\MediaUrlResolver;

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

        $dto->imageUrl    = MediaUrlResolver::joinStored($entity->getImagePath(), rtrim($host, '/') . '/assets/uploads/explore');
        
        $videoPath = $entity->getVideoPath();
        if (str_starts_with((string)$videoPath, 'http')) {
            $video = $videoPath;
        } else if ($videoPath && str_starts_with($videoPath, '/media/secure/')) {
            $video = MediaUrlResolver::joinStored($videoPath, rtrim($host, '/') . '/assets/uploads/explore'); // clé de la médiathèque
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