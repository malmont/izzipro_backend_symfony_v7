<?php
namespace App\Dto;

use App\Entity\FraisDePort;

class FraisDePortOutputDTO
{
    public int $id;
    public string $name;
    public ?string $facture; // Modifier pour accepter null
    public ?string $image;
    public string $tracknumber;
    public float $price;
    public array $transporteur;

    public function __construct(FraisDePort $fraisDePort)
    {
        $this->id = $fraisDePort->getId();
        $this->name = $fraisDePort->getName();
        $this->facture = $fraisDePort->getFacture() ?? ''; // Assigner une chaîne vide si null
        $this->image = $fraisDePort->getImage();
        $this->tracknumber = $fraisDePort->getTracknumber();
        $this->price = $fraisDePort->getPrice();
        $this->transporteur = [
            'id' => $fraisDePort->getTransporteur()->getId(),
            'name' => $fraisDePort->getTransporteur()->getName(),
            'logo' => $fraisDePort->getTransporteur()->getLogo(),
            'contact' => $fraisDePort->getTransporteur()->getContact(),
        ];
    }
}
