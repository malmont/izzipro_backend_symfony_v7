<?php
namespace App\Dto;

use App\Entity\NoteDeFrais;

class NoteDeFraisOutputDTO
{
    public int $id;
    public string $description;
    public int $montant; // cents (lot G1, 09/11/2026)
    public string $currency;
    public string $date;
    public ?string $imageNdf;
    public ?array $document; // justificatif lu par IA { id, type, invoiceNumber, invoiceDate } | null (lot L1, 10/11/2026)
    public ?int $tripId; // voyage d'achat rattaché (lot B1a, 11/11/2026)
    public ?int $typeNoteDeFraisId; // type de la note, exigé par le PUT : préremplit la modification (12/11/2026)

    public function __construct(NoteDeFrais $noteDeFrais, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)
    {
        $this->currency = $currency;
        $this->id = $noteDeFrais->getId();
        $this->description = $noteDeFrais->getDescription();
        $this->montant = (int) $noteDeFrais->getMontant();
        $this->date = $noteDeFrais->getDate()->format('Y-m-d');
        $photo = $noteDeFrais->getTypeNoteDeFrais();
        $this->imageNdf = $photo ? $host . '/assets/images/' . $photo->getImage() : null;
        $this->document = $noteDeFrais->getDocument()?->invoiceSummary();
        $this->tripId = $noteDeFrais->getTripId();
        $this->typeNoteDeFraisId = $photo?->getId();
    }
}
