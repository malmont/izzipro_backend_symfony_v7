<?php
namespace App\Dto;

use App\Entity\Emploi;

class EmploiOutputDto
{
    public int $id;
    public ?string $titre;
    public ?string $description;
    public int $nombreCandidatures;

    public function __construct(Emploi $emploi, string $locale)
    {
        $translation = $emploi->getTranslation($locale);
        $this->id = $emploi->getId();
        $this->titre = $translation?->getTitre() ?? $emploi->getTitre();
        $this->description = $translation?->getDescription() ?? $emploi->getDescription();
        $this->nombreCandidatures = $emploi->getCandidatures()->count();
    }
}