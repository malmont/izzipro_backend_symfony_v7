<?php
namespace App\Dto;

use App\Entity\TransactionCaisse;

class TransationCaisseDTO
{
    private int $id;
    private ?string $transactionDate;
    private float $amount;
    private string $transactionType;
    private array $cashDetails = [];

    public function __construct(TransactionCaisse $transactionCaisse)
    {
        $this->id = $transactionCaisse->getId();
        $this->transactionDate = $transactionCaisse->getTransactionDate() ? $transactionCaisse->getTransactionDate()->format('Y-m-d H:i:s') : null;
        $this->amount = $transactionCaisse->getAmount();
        $this->transactionType = $transactionCaisse->getTransactionType()->getName();

        // Ajouter les CashDetails
        foreach ($transactionCaisse->getCashDetails() as $cashDetail) {
            $this->cashDetails[] = [
                'typeCash' => $cashDetail->getTypeCash()->getName(),
                'value' => $cashDetail->getTypeCash()->getValue(),
                'nombreItems' => $cashDetail->getNombreItems(),
                'total' => $cashDetail->getTypeCash()->getValue() * $cashDetail->getNombreItems(),
            ];
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'transactionDate' => $this->transactionDate,
            'amount' => $this->amount,
            'transactionType' => $this->transactionType,
            'cashDetails' => $this->cashDetails,
        ];
    }
}
