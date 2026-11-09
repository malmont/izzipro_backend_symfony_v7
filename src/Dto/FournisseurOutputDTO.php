<?php
namespace App\Dto;

use App\Entity\Fournisseur;

class FournisseurOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $photo;
    public ?string $adresse;
    public ?string $ville;
    public ?string $pays;
    public ?string $tel;
    /** Devise d'achat propre au fournisseur (lot G6) ; null = devise du site */
    public ?string $purchaseCurrency;
    /** Devise des montants de ses commandes : la sienne, sinon celle du site */
    public string $currency;

    public function __construct(Fournisseur $fournisseur, string $host, string $siteCurrency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)
    {
        $this->purchaseCurrency = $fournisseur->getCurrency();
        $this->currency = $fournisseur->getCurrency() ?? $siteCurrency;
        $this->id = $fournisseur->getId();
        $this->name = $fournisseur->getName();
        $image = $fournisseur->getTypeFournisseur();
        $this->photo = $image ? $host . '/assets/images/' . $image->getPhoto() : null;
        $this->adresse = $fournisseur->getAdresse();
        $this->ville = $fournisseur->getVille();
        $this->pays = $fournisseur->getPays();
        $this->tel = $fournisseur->getTel();
    }
}
