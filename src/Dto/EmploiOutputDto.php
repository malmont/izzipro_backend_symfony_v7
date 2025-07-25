<?php
namespace App\Dto;

use App\Entity\Emploi;

class EmploiOutputDto
{
    public int $id;
    public string $titre;
    public ?string $description;
    public int $nombreCandidatures;

    public function __construct(Emploi $emploi)
    {
        $this->id = $emploi->getId();
        $this->titre = $emploi->getTitre();
        $this->description = $emploi->getDescription();
        $this->nombreCandidatures = $emploi->getCandidatures()->count();
    }
}