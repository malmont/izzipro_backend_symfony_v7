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
    public int $price; // cents (lot G1, 09/11/2026)
    public string $currency;
    public array $transporteur;
    public ?array $document; // justificatif lu par IA { id, type, invoiceNumber, invoiceDate } | null (lot L1, 10/11/2026)

    public function __construct(FraisDePort $fraisDePort, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)
    {
        $this->currency = $currency;
        $this->id = $fraisDePort->getId();
        $this->name = $fraisDePort->getName();
        $this->facture = $fraisDePort->getFacture() ?? ''; 
        $photo = $fraisDePort->getTransporteur();
        $this->image = $photo ? $host . '/assets/uploads/Carrier/' . $photo->getLogo() : null;
        $this->tracknumber = $fraisDePort->getTracknumber();
        $this->price = (int) $fraisDePort->getPrice();
        $this->transporteur = [
            'id' => $fraisDePort->getTransporteur()->getId(),
            'name' => $fraisDePort->getTransporteur()->getName(),
            'logo' => $fraisDePort->getTransporteur()->getLogo(),
            'contact' => $fraisDePort->getTransporteur()->getContact(),
        ];
        $this->document = $fraisDePort->getDocument()?->invoiceSummary();
    }
}
