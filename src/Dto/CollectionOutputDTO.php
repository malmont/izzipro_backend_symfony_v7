<?php

namespace App\Dto;

use App\Entity\Collections;

class CollectionOutputDTO
{
    public int $id;
    public int $budgetCollection; // cents (lot G1, 09/11/2026)
    public string $currency;
    public string $startDateCollection;
    public string $endDateCollection;
    public bool $del;
    public bool $isClosed;
    public string $nomCollection;
    public ?string $photoCollection;
    public ?array $user;

    public function __construct(Collections $collection, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT)
    {
        $this->currency = $currency;
        $this->id = $collection->getId();
        $this->budgetCollection = (int) $collection->getBudgetCollection();
        $this->startDateCollection = $collection->getStartDateCollection()->format('Y-m-d H:i:s');
        $this->endDateCollection = $collection->getEndDateCollection()->format('Y-m-d H:i:s');
        $this->del = $collection->isDel();
        $this->isClosed = $collection->getIsClosed();
        $this->nomCollection = $collection->getNomCollection();

        $photo = $collection->getPhotoCollections();
        $this->photoCollection = $photo ? $host . '/assets/images/' . $photo->getImageUrl() : null;

        $user = $collection->getUserCollections();
        $this->user = $user ? [
            'id' => $user->getId(),
            'name' => $user->getFirstName() . ' ' . $user->getLastName(),
            'email' => $user->getEmail(),
        ] : null;
    }

    public static function fromEntity(Collections $collection, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT): self
    {
        return new self($collection, $host, $currency);
    }
}
