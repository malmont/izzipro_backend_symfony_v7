<?php

namespace App\Services\CheckoutService;

use App\Services\StripeService\StripeService;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Intents Stripe du site courant : compte de la plateforme (site interne) ou compte connecté avec commission de 1 %,
 * capture manuelle (la commande capture). Les erreurs de l'API sont journalisées et rendent null.
 */
final class StripeCheckoutPaymentGateway implements CheckoutPaymentGatewayInterface
{
    /** Statuts d'un intent encore modifiable (pas confirmé) */
    private const UPDATABLE = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    public function __construct(private readonly StripeService $stripe, private readonly LoggerInterface $logger)
    {
    }

    public function create(int $amount, string $currency, array $metadata): ?array
    {
        $options = $this->stripe->accountOptions();
        if ($options === null) {
            $this->logger->error('[Checkout] Paiement impossible : le site n\'a pas de compte Stripe actif.');

            return null;
        }
        $params = ['amount' => $amount, 'currency' => strtolower($currency), 'payment_method_types' => ['card'], 'capture_method' => 'manual', 'metadata' => $metadata];
        if (isset($options['stripe_account'])) {
            $params['application_fee_amount'] = $this->stripe->onlineFee($amount);
        }

        return $this->call(fn (StripeClient $c) => $c->paymentIntents->create($params, $options), 'création');
    }

    public function retrieve(string $id): ?array
    {
        $options = $this->stripe->accountOptions();

        return $options === null ? null : $this->call(fn (StripeClient $c) => $c->paymentIntents->retrieve($id, ['expand' => ['latest_charge']], $options), 'lecture');
    }

    public function updateAmount(string $id, int $amount): ?array
    {
        $current = $this->retrieve($id);
        if ($current === null || !in_array($current['status'], self::UPDATABLE, true)) {
            return null;
        }
        if ($current['amount'] === $amount) {
            return $current;
        }
        $options = $this->stripe->accountOptions() ?? [];
        $params = ['amount' => $amount] + (isset($options['stripe_account']) ? ['application_fee_amount' => $this->stripe->onlineFee($amount)] : []);

        return $this->call(fn (StripeClient $c) => $c->paymentIntents->update($id, $params, $options), 'mise à jour');
    }

    public function capture(string $id): ?array
    {
        $current = $this->retrieve($id);
        if ($current === null || $current['status'] !== 'requires_capture') {
            return $current;
        }
        $options = $this->stripe->accountOptions() ?? [];

        return $this->call(fn (StripeClient $c) => $c->paymentIntents->capture($id, ['expand' => ['latest_charge']], $options), 'capture');
    }

    public function cancel(string $id): bool
    {
        $current = $this->retrieve($id);
        if ($current === null) {
            $this->logger->error(sprintf('[Checkout] Annulation de l\'autorisation %s impossible : intent illisible', $id));

            return false;
        }
        if ($current['status'] === 'canceled') {
            return true;
        }
        if (!in_array($current['status'], ['requires_capture', ...self::UPDATABLE], true)) {
            $this->logger->error(sprintf('[Checkout] Annulation de l\'autorisation %s impossible : statut %s', $id, $current['status']));

            return false;
        }
        $result = $this->call(fn (StripeClient $c) => $c->paymentIntents->cancel($id, [], $this->stripe->accountOptions() ?? []), 'annulation');
        if ($result === null || $result['status'] !== 'canceled') {
            // Avant le 01/11/2026, cet échec n'était que journalisé par call() sans l'identifiant ; le client restait autorisé
            $this->logger->error(sprintf('[Checkout] Annulation de l\'autorisation %s en échec (statut %s)', $id, $result['status'] ?? 'inconnu'));

            return false;
        }

        return true;
    }

    public function refund(string $id, ?int $amountCents, string $idempotencyKey): ?array
    {
        $options = $this->stripe->accountOptions();
        if ($options === null) {
            $this->logger->error('[Checkout] Remboursement impossible : le site n\'a pas de compte Stripe actif.');

            return null;
        }
        $params = ['payment_intent' => $id];
        if ($amountCents !== null && $amountCents > 0) {
            $params['amount'] = $amountCents;
        }
        if (isset($options['stripe_account'])) {
            $params['refund_application_fee'] = true; // la plateforme rend sa commission au prorata
        }
        try {
            $refund = (new StripeClient($this->stripe->secretKey()))->refunds->create($params, $options + ['idempotency_key' => $idempotencyKey]);

            return ['id' => (string) $refund->id, 'status' => (string) $refund->status, 'amount' => (int) $refund->amount];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error(sprintf('[Checkout] Stripe (remboursement %s) : %s', $id, $e->getMessage()));

            return null;
        }
    }

    private function call(callable $action, string $label): ?array
    {
        try {
            return self::normalize($action(new StripeClient($this->stripe->secretKey())));
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error(sprintf('[Checkout] Stripe (%s) : %s', $label, $e->getMessage()));

            return null;
        }
    }

    public static function normalize(PaymentIntent $intent): array
    {
        $charge = is_object($intent->latest_charge ?? null) ? $intent->latest_charge : null;

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
            'amount' => (int) $intent->amount,
            'currency' => (string) $intent->currency,
            'clientSecret' => $intent->client_secret,
            'metadata' => $intent->metadata?->toArray() ?? [],
            'receiptUrl' => $charge?->receipt_url,
            'cardBrand' => $charge?->payment_method_details?->card?->brand,
            'last4' => $charge?->payment_method_details?->card?->last4,
            'riskLevel' => $charge?->outcome?->risk_level,
        ];
    }
}
