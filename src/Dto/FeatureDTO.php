<?php
namespace App\Dto;

use App\Entity\Feature;

class FeatureDTO
{
    public int    $id;
    /** Contenu d'exemple encore à remplacer (copié du site de référence, jamais modifié) */
    public bool $isDemo = false;
    public ?string $title;
    public ?string $iconUrl;

    private function __construct() {}

    public static function fromEntity(Feature $feature, string $host, string $locale): self
    {
        $dto = new self();
        $translation = $feature->getTranslation($locale);

        $dto->id    = $feature->getId();
        $dto->isDemo = $feature->isDemo();
        $dto->title = $translation?->getTitle() ?? $feature->getTitle();
        
        // Fichier téléversé (icons/), URL absolue ou média de la médiathèque (/media/secure/…, éditeur de la page)
        $dto->iconUrl = \App\Services\MediaUrlResolver::joinStored($feature->getIconpath(), rtrim($host, '/') . '/assets/uploads/icons');
        
        return $dto;
    }
}