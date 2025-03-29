<?php

namespace App\Dto;

use App\Entity\Collections;

class CollectionOutputDTOMessage
{
    public int $id;
    public float $budgetCollection;
    public string $startDateCollection;
    public string $endDateCollection;
    public bool $del;
    public bool $isClosed;
    public string $nomCollection;
    public CollectionPicture $photoCollections;
    public ?array $user;

    public function __construct(Collections $collection)
    {
        $this->id = $collection->getId();
        $this->budgetCollection = $collection->getBudgetCollection();
        $this->startDateCollection = $collection->getStartDateCollection()->format('Y-m-d H:i:s');
        $this->endDateCollection = $collection->getEndDateCollection()->format('Y-m-d H:i:s');
        $this->del = $collection->isDel();
        $this->isClosed = $collection->getIsClosed();
        $this->nomCollection = $collection->getNomCollection();
        $this->photoCollections = $collection->getPhotoCollections();
        $user = $collection->getUserCollections();
        $this->user = $user ? [
            'id' => $user->getId(),
            'name' => $user->getFirstName() . ' ' . $user->getLastName(),
            'email' => $user->getEmail(),
        ] : null;
    }

    public static function fromEntity(Collections $collection): self
    {
        return new self($collection);
    }
}
