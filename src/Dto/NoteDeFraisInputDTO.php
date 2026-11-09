<?php
namespace App\Dto;

class NoteDeFraisInputDTO
{
    public string $description;
    public int $montant; // cents (lot G1, 09/11/2026)
    public string $date;
    public int $typeNoteDeFraisId;

    public function __construct(string $description, int $montant, string $date, int $typeNoteDeFraisId)
    {
        $this->description = $description;
        $this->montant = $montant;
        $this->date = $date;
        $this->typeNoteDeFraisId = $typeNoteDeFraisId ;
    }
}
