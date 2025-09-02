<?php
namespace App\Dto;

use App\Entity\PresentationGroup;

class PresentationGroupOutputDto
{
    public int $id;
    public string $titre;
    public array $presentations = [];

    public function __construct(PresentationGroup $entity, string $baseImageUrl)
    {
        $this->id = $entity->getId();
        $this->titre = $entity->getTitre();

        foreach ($entity->getPresentations() as $presentation) {
            // On utilise le DTO de Présentation existant pour la cohérence
            $this->presentations[] = new PresentationOutputDto($presentation, $baseImageUrl);
        }
    }
}
