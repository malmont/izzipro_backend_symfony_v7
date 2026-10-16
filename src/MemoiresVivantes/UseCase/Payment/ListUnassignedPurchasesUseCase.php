<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\Entity\User;
use App\MemoiresVivantes\Services\ChapterPaymentService;

/** GET /api/memoires/mobile/purchases/unassigned : achats réglés pas encore affectés à un chapitre */
class ListUnassignedPurchasesUseCase
{
    public function __construct(private readonly ChapterPaymentService $payments)
    {
    }

    public function execute(User $user): array
    {
        return ['purchases' => array_map(fn ($p) => [
            'transactionId' => $p->getTransactionId(), 'store' => $p->getStore(), 'product' => $p->getProductId(),
            'amount' => $p->getAmount(), 'currency' => $p->getCurrency(), 'purchasedAt' => $p->getPurchasedAt()?->format(\DateTimeInterface::ATOM),
        ], $this->payments->unassigned($user))];
    }
}
