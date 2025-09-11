<?php
namespace App\Dto;

use App\Entity\PresentationGroup;

class PresentationGroupOutputDto
{
    public int $id;
    public ?string $titre;
    public array $presentations = [];


    public function __construct(PresentationGroup $entity, string $baseImageUrl, string $locale)
    {
        // --- Logique "en attente" pour le titre du groupe ---
        // $translation = $entity->getTranslation($locale);
        // $this->titre = $translation ? $translation->getTitre() : $entity->getTitre();
        $this->id = $entity->getId();
        $this->titre = $entity->getTitre();

        foreach ($entity->getPresentations() as $presentation) {
            $this->presentations[] = new PresentationOutputDto($presentation, $baseImageUrl, $locale);
        }
    }
}