<?php

namespace App\Dto;

use App\Entity\Caisse;
use App\Entity\CashRegister;

/** Poste de caisse (GET/POST/PUT /api/cash-registers) avec sa session ouverte, ou null */
class CashRegisterOutputDto
{
    public static function toArray(CashRegister $register, ?Caisse $openSession): array
    {
        return [
            'id' => $register->getId(),
            'name' => $register->getName(),
            'isActive' => $register->isActive(),
            'createdAt' => $register->getCreatedAt()->format('Y-m-d H:i:s'),
            // Terminal de paiement Stripe du poste (C4, 23/10/2026) : ajout, null sans terminal
            'terminal' => $register->hasTerminal() ? ['readerId' => $register->getStripeReaderId(), 'label' => $register->getStripeReaderLabel(), 'simulated' => $register->isStripeReaderSimulated()] : null,
            'openSession' => $openSession ? [
                'id' => $openSession->getId(),
                'amountTotal' => $openSession->getAmountTotal(),
                'fonDeCaisse' => $openSession->getFonDeCaisse(),
                'createdAt' => $openSession->getCreatedAt()?->format('Y-m-d H:i:s'),
            ] : null,
        ];
    }
}
