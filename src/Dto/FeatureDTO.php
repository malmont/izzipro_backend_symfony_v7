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
        $dto->iconUrl = $path
            ? rtrim($host, '/') . '/assets/uploads/icons/' . $path
            : null;
        
        return $dto;
    }
}