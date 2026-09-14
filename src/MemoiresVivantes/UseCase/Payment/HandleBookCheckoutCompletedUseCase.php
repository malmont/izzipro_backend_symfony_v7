<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPaymentService;

class HandleBookCheckoutCompletedUseCase
{
    public function __construct(
        private readonly BookPaymentService $bookPaymentService
    ) {}

    /**
     * Traite l'événement Stripe checkout.session.completed pour mettre à jour le livre et envoyer les factures/alertes.
     *
     * @param string $sessionId
     * @param array $sessionData
     * @return Book|null
     */
    public function execute(string $sessionId, array $sessionData = []): ?Book
    {
        return $this->bookPaymentService->handleCheckoutCompleted($sessionId, $sessionData);
    }
}
