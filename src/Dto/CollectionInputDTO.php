<?php
namespace App\Dto;

class CollectionInputDTO
{
    public float $budgetCollection;
    public \DateTime $startDateCollection;
    public \DateTime $endDateCollection;
    public bool $del;
    public string $nomCollection;
    public ?string $photoCollection;
    public int $userId;

    public function __construct(array $data)
    {
        $this->budgetCollection = (float)($data['budgetCollection'] ?? 0);
        $this->startDateCollection = new \DateTime($data['startDateCollection'] ?? 'now');
        $this->endDateCollection = new \DateTime($data['endDateCollection'] ?? 'now');
        $this->del = (bool)($data['del'] ?? false);
        $this->nomCollection = $data['nomCollection'] ?? '';
        $this->photoCollection = $data['photoCollection'] ?? null;
        $this->userId = (int)($data['userId'] ?? 0);
    }
}
