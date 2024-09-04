<?php
namespace App\Dto;

class NoteDeFraisInputDTO
{
    public string $description;
    public float $montant;
    public string $date;
    public ?string $imageNdf;

    public function __construct(string $description, float $montant, string $date, ?string $imageNdf = null)
    {
        $this->description = $description;
        $this->montant = $montant;
        $this->date = $date;
        $this->imageNdf = $imageNdf;
    }
}
