<?php
namespace App\Dto;

class NoteDeFraisInputDTO
{
    public string $description;
    public float $montant;
    public string $date;
    public int $typeNoteDeFraisId;

    public function __construct(string $description, float $montant, string $date, int $typeNoteDeFraisId)
    {
        $this->description = $description;
        $this->montant = $montant;
        $this->date = $date;
        $this->typeNoteDeFraisId = $typeNoteDeFraisId ;
    }
}
