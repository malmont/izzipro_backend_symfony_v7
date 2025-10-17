<?php
// src/UseCase/PaymentUseCase/CreatePaymentUseCase.php

namespace App\UseCase\PaymentUseCase;

use App\Services\StripePaymentService\StripePaymentService;
use Stripe\PaymentIntent; 

class CreatePaymentUseCase
{
    private StripePaymentService $paymentService;

    public function __construct(StripePaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function execute(string $paymentIntentId): array
    {
        $result = $this->paymentService->createPayment($paymentIntentId);

        if ($result['success']) {
            $paymentIntent = $result['payment'];
            $charge = $paymentIntent->charges->data[0] ?? null;

            $response = [
                'success' => true,
                'payment' => [
                    'stripePaymentId' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                    'receiptUrl' => $charge ? $charge->receipt_url : null,
                    'cardBrand' => $charge ? $charge->payment_method_details->card->brand : null,
                    'last4' => $charge ? $charge->payment_method_details->card->last4 : null,
                    'riskLevel' => $charge ? $charge->outcome->risk_level : null,
                ]
            ];
            return $response;
        }

        return ['success' => false, 'errors' => $result['errors']];
    }
}