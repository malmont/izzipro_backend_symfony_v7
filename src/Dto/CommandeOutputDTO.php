<?php
namespace App\Dto;

use App\Entity\Commande;

class CommandeOutputDTO
{
    public int $id;
    public int $budget; // cents, dans la devise de la commande (lot G1, 09/11/2026 ; devise d'achat lot G6)
    /** Devise des montants de la commande : devise d'achat du fournisseur figée à la création, sinon celle du site */
    public string $currency;
    public string $siteCurrency;
    /** Taux vers la devise du site (null quand la commande est dans la devise du site) */
    public ?float $exchangeRate;
    public string $date;
    public string $name;
    public ?string $photo;
    public int $collectionId;
    public ?FournisseurOutputDTO $fournisseur;
    public string $kind; // standard | reprise (lot P3, 10/11/2026)
    public ?array $document; // justificatif lu par IA { id, type, invoiceNumber, invoiceDate } | null (lot L1, 10/11/2026)
    public string $receptionStatus; // none | partial | complete (lot R1)
    public ?int $tripId; // voyage d'achat rattaché (lot B1a, 11/11/2026)

    public function __construct(Commande $commande, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)
    {
        $this->siteCurrency = $currency;
        $this->currency = $commande->getCurrency() ?? $currency;
        $this->exchangeRate = $commande->getCurrency() === null ? null : $commande->getExchangeRate();
        $this->id = $commande->getId();
        $this->budget = (int) $commande->getBudget();
        $this->date = $commande->getDate()->format('Y-m-d H:i:s');
        $this->name = $commande->getName();
        $this->kind = $commande->getKind();
        $this->document = $commande->getDocument()?->invoiceSummary();
        $this->receptionStatus = $commande->receptionStatus();
        $this->tripId = $commande->getTripId();
        $photoCommande = $commande->getCommandepictures();
        $this->photo = $photoCommande ? $host . '/assets/images/' . $photoCommande->getImageUrl() : null;

        $this->collectionId = $commande->getCollections()->getId();
        
        $fournisseur = $commande->getFournisseur();
        $this->fournisseur = $fournisseur ? new FournisseurOutputDTO($fournisseur,$host) : null;
    }
}
