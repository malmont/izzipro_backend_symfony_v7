<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\MemoiresVivantes\Entity\Book;

class GetBookPaymentStatusUseCase
{
    public function __construct(
        private readonly SyncBookPaymentStatusUseCase $syncBookPaymentStatusUseCase
    ) {}

    /**
     * Synchronise le statut de paiement du livre auprès de Stripe et retourne les informations de paiement.
     *
     * @param Book $book
     * @return array
     */
    public function execute(Book $book): array
    {
        if ($book->getPaymentStatus() === 'pending') {
            $this->syncBookPaymentStatusUseCase->execute($book);
        }

        return [
            'book_id' => (string) $book->getId(),
            'title' => $book->getTitle(),
            'payment_status' => $book->getPaymentStatus(),
            'payment_link_url' => $book->getPaymentLinkUrl(),
            'payment_amount' => $book->getPaymentAmount(),
            'payment_currency' => $book->getPaymentCurrency(),
            'paid_at' => $book->getPaidAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
