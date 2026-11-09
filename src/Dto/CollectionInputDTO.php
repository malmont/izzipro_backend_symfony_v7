<?php

namespace App\Dto;

use App\Entity\CollectionPicture;
use DateTime;

class CollectionInputDTO
{
    public int $budgetCollection;
    public DateTime $startDateCollection;
    public DateTime $endDateCollection;
    public bool $del;
    public string $nomCollection;
    public ?CollectionPicture $photoCollections = null;
    public int $userId;

    public function __construct(array $data)
    {
        $this->budgetCollection = (int) ($data['budgetCollection'] ?? 0); // cents (lot G1, 09/11/2026)
        $this->startDateCollection = new DateTime($data['startDateCollection'] ?? 'now');
        $this->endDateCollection = new DateTime($data['endDateCollection'] ?? 'now');
        $this->del = (bool)($data['del'] ?? false);
        $this->nomCollection = $data['nomCollection'] ?? '';
        $this->userId = (int)($data['userId'] ?? 0);
    }
}
