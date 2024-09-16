<?php
namespace App\Dto;

class CaisseDTO
{
    private int $id;
    private ?float $amountTotal;
    private ?string $createdAt;
    private ?bool $isOpen;
    private array $transactionCaisses;

    public function __construct(
        int $id,
        ?float $amountTotal,
        ?string $createdAt,
        ?bool $isOpen,
        array $transactionCaisses
    )
    {
        $this->id = $id;
        $this->amountTotal = $amountTotal;
        $this->createdAt = $createdAt;
        $this->isOpen = $isOpen;
        $this->transactionCaisses = $transactionCaisses;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amountTotal' => $this->amountTotal,
            'createdAt' => $this->createdAt,
            'isOpen' => $this->isOpen,
            'transactionCaisses' => array_map(fn($item) => $item->toArray(), $this->transactionCaisses),
        ];
    }
}
