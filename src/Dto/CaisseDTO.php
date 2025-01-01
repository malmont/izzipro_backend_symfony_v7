<?php
namespace App\Dto;

class CaisseDTO
{
    private int $id;
    private ?float $amountTotal;
    private ?float $fonDeCaisse;
    private ?string $createdAt;
    private ?bool $isOpen;
    private array $transactionCaisses;

    public function __construct(
        int $id,
        ?float $amountTotal,
        ?float $fonDeCaisse,
        ?string $createdAt,
        ?bool $isOpen,
        array $transactionCaisses
    )
    {
        $this->id = $id;
        $this->amountTotal = $amountTotal;
        $this->fonDeCaisse = $fonDeCaisse;
        $this->createdAt = $createdAt;
        $this->isOpen = $isOpen;
        $this->transactionCaisses = $transactionCaisses;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amountTotal' => $this->amountTotal,
            'fonDeCaisse' => $this->fonDeCaisse,
            'createdAt' => $this->createdAt,
            'isOpen' => $this->isOpen,
            'transactionCaisses' => array_map(fn($item) => $item->toArray(), $this->transactionCaisses),
        ];
    }
}
