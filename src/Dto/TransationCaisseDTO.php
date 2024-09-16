<?php
namespace App\Dto;

use App\Entity\TransactionCaisse;

class TransationCaisseDTO
{
    private int $id;
    private ?string $transactionDate;
    private float $amount;
    private string $transactionType;

    public function __construct(TransactionCaisse $transactionCaisse)
    {
        $this->id = $transactionCaisse->getId();
        $this->transactionDate = $transactionCaisse->getTransactionDate() ? $transactionCaisse->getTransactionDate()->format('Y-m-d H:i:s') : null;
        $this->amount = $transactionCaisse->getAmount();
        $this->transactionType = $transactionCaisse->getTransactionType()->getName();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'transactionDate' => $this->transactionDate,
            'amount' => $this->amount,
            'transactionType' => $this->transactionType,
        ];
    }
}
