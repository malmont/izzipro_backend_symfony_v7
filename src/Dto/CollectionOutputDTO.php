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
    /** Périodes (lot P1, 10/11/2026) : manual | period ; periodKey null pour une manuelle ; status open | closed calculé d'après la date */
    public string $kind;
    public ?string $periodKey;
    public string $status;
    public ?string $closedAt;
    public ?string $reopenedAt;

    public function __construct(Collections $collection, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT, ?\App\Gestion\Services\PeriodCalendar $calendar = null)
    {
        $this->currency = $currency;
        $this->kind = $collection->getKind();
        $this->periodKey = $collection->getPeriodKey();
        $closed = $calendar ? $calendar->isClosed($collection) : (bool) $collection->getIsClosed();
        $this->status = $closed ? 'closed' : 'open';
        $this->closedAt = $calendar ? $calendar->closedAt($collection)?->format('Y-m-d') : ($collection->getClosedAt()?->format('Y-m-d'));
        $this->reopenedAt = $collection->getReopenedAt()?->format('Y-m-d');
        $this->id = $collection->getId();
        $this->budgetCollection = (int) $collection->getBudgetCollection();
        $this->startDateCollection = $collection->getStartDateCollection()->format('Y-m-d H:i:s');
        $this->endDateCollection = $collection->getEndDateCollection()->format('Y-m-d H:i:s');
        $this->del = $collection->isDel();
        $this->isClosed = $closed; // vrai aussi pour une période fermée par la date
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

    public static function fromEntity(Collections $collection, string $host, string $currency = \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT, ?\App\Gestion\Services\PeriodCalendar $calendar = null): self
    {
        return new self($collection, $host, $currency, $calendar);
    }
}
