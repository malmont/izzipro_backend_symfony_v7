<?php

namespace App\Tests\Fake;

use App\Services\StripePaymentService\StripePaymentService;
use Stripe\PaymentIntent;

/** POST /api/payment en test : lit les intents simulés de FakeCheckoutPaymentGateway au lieu d'appeler Stripe */
final class FakeStripePaymentService extends StripePaymentService
{
    public function createPayment(string $paymentIntentId): array
    {
        $intent = FakeCheckoutPaymentGateway::$intents[$paymentIntentId] ?? null;
        if ($intent === null) {
            return ['success' => false, 'errors' => ['No such payment_intent: ' . $paymentIntentId]];
        }
        if ($intent['status'] !== 'succeeded') {
            return ['success' => false, 'errors' => ['Le paiement n\'a pas abouti. Statut : ' . $intent['status']]];
        }

        return ['success' => true, 'payment' => PaymentIntent::constructFrom(['id' => $intent['id'], 'status' => 'succeeded', 'charges' => ['data' => [[
            'receipt_url' => $intent['receiptUrl'], 'payment_method_details' => ['card' => ['brand' => $intent['cardBrand'], 'last4' => $intent['last4']]], 'outcome' => ['risk_level' => $intent['riskLevel']],
        ]]]])];
    }
}
