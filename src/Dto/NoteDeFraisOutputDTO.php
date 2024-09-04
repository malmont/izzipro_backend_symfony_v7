<?php
namespace App\Dto;

use App\Entity\NoteDeFrais;

class NoteDeFraisOutputDTO
{
    public int $id;
    public string $description;
    public float $montant;
    public string $date;
    public ?string $imageNdf;

    public function __construct(NoteDeFrais $noteDeFrais)
    {
        $this->id = $noteDeFrais->getId();
        $this->description = $noteDeFrais->getDescription();
        $this->montant = $noteDeFrais->getMontant();
        $this->date = $noteDeFrais->getDate()->format('Y-m-d');
        $this->imageNdf = $noteDeFrais->getImageNdf();
    }
}
