<?php
namespace App\Dto;

use App\Entity\PresentationGroup;
use App\Dto\PresentationOutputDto; 

class PresentationGroupOutputDto
{
    public int $id;
    public ?string $titre;
    public array $presentations = [];

    public function __construct(PresentationGroup $entity, string $baseImageUrl, string $locale)
    {
        $translation = $entity->getTranslation($locale);
        
        $this->id = $entity->getId();
        $this->titre = $translation?->getTitre() ?? $entity->getTitre();

        // ordre choisi dans l'éditeur des landing pages (ordre de la base sinon)
        foreach ($entity->getOrderedPresentations() as $presentation) {
            $this->presentations[] = new PresentationOutputDto($presentation, $baseImageUrl, $locale);
        }
    }
}