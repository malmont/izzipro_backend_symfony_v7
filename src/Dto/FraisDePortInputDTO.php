<?php
namespace App\Dto;

class FraisDePortInputDTO
{
    public string $name;
    public string $facture;
    public string $tracknumber;
    public int $price; // cents (lot G1, 09/11/2026)
    public int $transporteurId;

    public function __construct(string $name, string $facture, string $tracknumber, int $price, int $transporteurId)
    {
        $this->name = $name;
        $this->facture = $facture;
        $this->tracknumber = $tracknumber;
        $this->price = $price;
        $this->transporteurId = $transporteurId;
    }
}
