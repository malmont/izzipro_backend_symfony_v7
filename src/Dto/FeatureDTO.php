<?php
namespace App\Dto;

use App\Entity\Feature;

class FeatureDTO
{
    public int    $id;
    public ?string $title;
    public ?string $iconUrl;

    private function __construct() {}

    public static function fromEntity(Feature $feature, string $host, string $locale): self
    {
        $dto = new self();
        $translation = $feature->getTranslation($locale);

        $dto->id    = $feature->getId();
        $dto->title = $translation?->getTitle() ?? $feature->getTitle();
        
        $path = $feature->getIconpath();
        if (str_starts_with((string)$path, 'http')) {
            $image = $path;
        } else if ($path) {
            $cleanedHost = rtrim($host, '/');
            $image = $cleanedHost . '/assets/uploads/icons/' . $path;
        } else {
            $image = null;
        }
        $dto->iconUrl = $image;
        
        return $dto;
    }
}