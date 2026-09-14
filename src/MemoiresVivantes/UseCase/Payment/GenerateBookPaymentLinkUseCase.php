<?php

namespace App\MemoiresVivantes\UseCase\Payment;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPaymentService;

class GenerateBookPaymentLinkUseCase
{
    public function __construct(
        private readonly BookPaymentService $bookPaymentService
    ) {}

    /**
     * Crée une session de paiement Stripe Connect pour le livre et envoie optionnellement un email au destinataire.
     *
     * @return array{
     *     success: bool,
     *     url: string,
     *     session_id: string,
     *     amount: float,
     *     currency: string,
     *     payment_status: string,
     *     email_sent: bool
     * }
     */
    public function execute(
        Book $book,
        string $recipientEmail,
        float $amount,
        string $currency,
        string $successUrl,
        string $cancelUrl,
        bool $sendEmail = true,
        ?string $customMessage = null
    ): array {
        // 1. Création de la session Stripe Connect
        $result = $this->bookPaymentService->createPaymentSession(
            $book,
            $recipientEmail,
            $amount,
            $currency,
            $successUrl,
            $cancelUrl
        );

        // 2. Envoi par email si demandé
        $emailSent = false;
        if ($sendEmail) {
            $this->bookPaymentService->sendPaymentLinkEmail(
                $book,
                $recipientEmail,
                $result['url'],
                $amount,
                $currency,
                $customMessage
            );
            $emailSent = true;
        }

        $result['email_sent'] = $emailSent;
        return $result;
    }
}
