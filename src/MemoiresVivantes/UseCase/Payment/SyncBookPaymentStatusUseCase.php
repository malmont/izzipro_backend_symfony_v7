<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPaymentService;

class SyncBookPaymentStatusUseCase
{
    public function __construct(
        private readonly BookPaymentService $bookPaymentService
    ) {}

    /**
     * Vérifie en direct auprès de Stripe si la session est payée et synchronise le statut du livre.
     */
    public function execute(Book $book): void
    {
        $this->bookPaymentService->syncPaymentStatus($book);
    }
}
