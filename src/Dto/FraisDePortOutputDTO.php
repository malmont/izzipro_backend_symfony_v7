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
    public int $price; // cents (lot G1, 09/11/2026), dans la devise de la commande
    /** Prix en cents de la devise du site : price × taux de la commande (lot B3 bis, 12/11/2026) ; égal à price sans devise étrangère */
    public int $priceConverted;
    /** Devise de price : celle de la commande (lot B3 bis ; avant : celle du site, fausse pour une commande en devise étrangère) */
    public string $currency;
    /** Devise du site (lot B3 bis) : l'application de gestion y lit la devise du site avant `currency` */
    public string $siteCurrency;
    public array $transporteur;
    public ?array $document; // justificatif lu par IA { id, type, invoiceNumber, invoiceDate } | null (lot L1, 10/11/2026)

    public function __construct(FraisDePort $fraisDePort, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)
    {
        $commande = $fraisDePort->getCommande();
        $foreign = $commande?->getCurrency() !== null;
        $this->siteCurrency = $currency;
        $this->currency = $foreign ? $commande->getCurrency() : $currency;
        $this->id = $fraisDePort->getId();
        $this->name = $fraisDePort->getName();
        $this->facture = $fraisDePort->getFacture() ?? ''; 
        $photo = $fraisDePort->getTransporteur();
        $this->image = $photo ? $host . '/assets/uploads/Carrier/' . $photo->getLogo() : null;
        $this->tracknumber = $fraisDePort->getTracknumber();
        $this->price = (int) $fraisDePort->getPrice();
        $this->priceConverted = (int) round($this->price * ($foreign ? (float) ($commande->getExchangeRate() ?? 1) : 1.0));
        $this->transporteur = [
            'id' => $fraisDePort->getTransporteur()->getId(),
            'name' => $fraisDePort->getTransporteur()->getName(),
            'logo' => $fraisDePort->getTransporteur()->getLogo(),
            'contact' => $fraisDePort->getTransporteur()->getContact(),
        ];
        $this->document = $fraisDePort->getDocument()?->invoiceSummary();
    }
}
