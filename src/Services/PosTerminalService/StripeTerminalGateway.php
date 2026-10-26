<?php

namespace App\Services\PosTerminalService;

use App\Services\StripeService\StripeAccountResolver;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Terminal\Reader;

/**
 * Stripe Terminal sur le compte connecté du site (charges directes, comme la boutique en ligne) ; aucune donnée de carte conservée.
 * Clé et compte du mode de la caisse du site (M1, StripeAccountResolver : « Paiements de caisse : test | réel »).
 */
class StripeTerminalGateway implements PosTerminalGatewayInterface
{
    public function __construct(private readonly StripeAccountResolver $accounts, private readonly LoggerInterface $logger)
    {
    }

    public function createPaymentIntent(int $amount, string $currency, array $paymentMethodTypes, int $applicationFee, array $metadata, string $idempotencyKey): array
    {
        $params = [
            'amount' => $amount, 'currency' => strtolower($currency), 'payment_method_types' => $paymentMethodTypes,
            // manual_preferred : carte de crédit autorisée seulement (capture avec la vente), Interac (message unique) débité aussitôt
            'payment_method_options' => ['card_present' => ['capture_method' => 'manual_preferred']],
            'metadata' => $metadata, 'expand' => ['latest_charge'],
        ];
        if ($applicationFee > 0 && $this->accountOptions()) {
            $params['application_fee_amount'] = $applicationFee;
        }

        return self::intent($this->call(fn (StripeClient $c, array $o) => $c->paymentIntents->create($params, $o), $idempotencyKey, 'création'));
    }

    public function retrievePaymentIntent(string $id): ?array
    {
        try {
            return self::intent($this->call(fn (StripeClient $c, array $o) => $c->paymentIntents->retrieve($id, ['expand' => ['latest_charge']], $o), null, 'lecture'));
        } catch (PosTerminalGatewayException $e) {
            if ($e->stripeCode === 'resource_missing') {
                return null;
            }
            throw $e;
        }
    }

    public function capturePaymentIntent(string $id, int $applicationFee, string $idempotencyKey): array
    {
        $params = ['expand' => ['latest_charge']];
        if ($this->accountOptions()) {
            $params['application_fee_amount'] = max(0, $applicationFee);
        }

        return self::intent($this->call(fn (StripeClient $c, array $o) => $c->paymentIntents->capture($id, $params, $o), $idempotencyKey, 'capture'));
    }

    public function cancelPaymentIntent(string $id, string $idempotencyKey): array
    {
        return self::intent($this->call(fn (StripeClient $c, array $o) => $c->paymentIntents->cancel($id, ['expand' => ['latest_charge']], $o), $idempotencyKey, 'annulation'));
    }

    public function processOnReader(string $readerId, string $paymentIntentId, bool $customerCancellation, string $idempotencyKey): array
    {
        $params = ['payment_intent' => $paymentIntentId, 'process_config' => ['enable_customer_cancellation' => $customerCancellation]];

        return self::reader($this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->processPaymentIntent($readerId, $params, $o), $idempotencyKey, 'envoi au lecteur'));
    }

    public function retrieveReader(string $readerId): ?array
    {
        try {
            return self::reader($this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->retrieve($readerId, [], $o), null, 'lecture du lecteur'));
        } catch (PosTerminalGatewayException $e) {
            if ($e->stripeCode === 'resource_missing') {
                return null;
            }
            throw $e;
        }
    }

    public function cancelReaderAction(string $readerId): array
    {
        return self::reader($this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->cancelAction($readerId, [], $o), null, 'annulation sur le lecteur'));
    }

    public function setReaderDisplay(string $readerId, string $currency, array $lines, int $tax, int $total): void
    {
        $params = ['type' => 'cart', 'cart' => ['currency' => strtolower($currency), 'line_items' => $lines, 'tax' => $tax, 'total' => $total]];
        $this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->setReaderDisplay($readerId, $params, $o), null, 'affichage du panier');
    }

    public function refundOnline(string $paymentIntentId, int $amount, string $idempotencyKey): array
    {
        $params = ['payment_intent' => $paymentIntentId, 'amount' => $amount];
        if ($this->accountOptions()) {
            $params['refund_application_fee'] = true; // la plateforme rend sa commission sur une vente remboursée
        }

        return self::refund($this->call(fn (StripeClient $c, array $o) => $c->refunds->create($params, $o), $idempotencyKey, 'remboursement'));
    }

    public function refundOnReader(string $readerId, string $paymentIntentId, int $amount, string $idempotencyKey): array
    {
        $params = ['payment_intent' => $paymentIntentId, 'amount' => $amount];
        if ($this->accountOptions()) {
            $params['refund_application_fee'] = true;
        }

        return self::reader($this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->refundPayment($readerId, $params, $o), $idempotencyKey, 'remboursement sur le lecteur'));
    }

    public function retrieveRefund(string $refundId): ?array
    {
        try {
            return self::refund($this->call(fn (StripeClient $c, array $o) => $c->refunds->retrieve($refundId, [], $o), null, 'lecture du remboursement'));
        } catch (PosTerminalGatewayException $e) {
            if ($e->stripeCode === 'resource_missing') {
                return null;
            }
            throw $e;
        }
    }

    public function createLocation(string $displayName, array $address): string
    {
        return (string) $this->call(fn (StripeClient $c, array $o) => $c->terminal->locations->create(['display_name' => mb_substr($displayName, 0, 100), 'address' => $address], $o), null, 'création de la Location')->id;
    }

    public function listReaders(?string $locationId): array
    {
        $params = ['limit' => 100] + ($locationId !== null ? ['location' => $locationId] : []);

        return array_map(self::reader(...), $this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->all($params, $o), null, 'liste des lecteurs')->data);
    }

    public function createReader(string $registrationCode, string $label, string $locationId): array
    {
        $params = ['registration_code' => trim($registrationCode), 'label' => mb_substr($label, 0, 100), 'location' => $locationId];

        return self::reader($this->call(fn (StripeClient $c, array $o) => $c->terminal->readers->create($params, $o), null, 'appairage du lecteur'));
    }

    public function presentPaymentMethod(string $readerId, string $type, ?string $cardNumber): array
    {
        if ($this->isLiveMode()) {
            throw new PosTerminalGatewayException('SIMULATION_FORBIDDEN', 'Présentation de carte simulée impossible avec une clé Stripe de production', 403);
        }
        $params = ['type' => $type];
        if ($cardNumber !== null) {
            $params[$type] = ['number' => preg_replace('/\D/', '', $cardNumber)];
        }

        return self::reader($this->call(fn (StripeClient $c, array $o) => $c->testHelpers->terminal->readers->presentPaymentMethod($readerId, $params, $o), null, 'carte simulée'));
    }

    public function isLiveMode(): bool
    {
        return $this->accounts->mode(StripeAccountResolver::CHANNEL_POS) === StripeAccountResolver::LIVE;
    }

    public function createConnectionToken(?string $locationId): string
    {
        $params = $locationId !== null ? ['location' => $locationId] : [];
        $token = $this->call(fn (StripeClient $c, array $o) => $c->terminal->connectionTokens->create($params, $o), null, 'jeton de connexion');

        return (string) $token->secret;
    }

    public function listRefunds(string $paymentIntentId): array
    {
        $list = $this->call(fn (StripeClient $c, array $o) => $c->refunds->all(['payment_intent' => $paymentIntentId, 'limit' => 100], $o), null, 'liste des remboursements');

        return array_map(self::refund(...), $list->data);
    }

    /** @template T @param callable(StripeClient, array): T $action @return T */
    private function call(callable $action, ?string $idempotencyKey, string $label): mixed
    {
        $mode = $this->accounts->mode(StripeAccountResolver::CHANNEL_POS);
        $secretKey = $this->accounts->secretKey($mode);
        if ($secretKey === null) {
            throw new PosTerminalGatewayException('STRIPE_KEYS_MISSING', $mode === StripeAccountResolver::LIVE ? 'Clés Stripe réelles de la caisse non configurées sur le serveur' : 'Clé Stripe de test non configurée sur le serveur', 409);
        }
        $options = $this->accountOptions();
        if ($options === null) {
            throw new PosTerminalGatewayException('TERMINAL_UNAVAILABLE', $mode === StripeAccountResolver::LIVE ? 'Aucun compte Stripe réel actif sur ce site' : 'Aucun compte Stripe actif sur ce site', 409);
        }
        if ($idempotencyKey !== null) {
            $options['idempotency_key'] = $idempotencyKey;
        }
        try {
            return $action(new StripeClient($secretKey), $options);
        } catch (CardException $e) {
            throw new PosTerminalGatewayException('PAYMENT_DECLINED', $e->getMessage(), 402, $e->getDeclineCode() ?: $e->getStripeCode(), $e);
        } catch (ApiConnectionException $e) {
            $this->logger->error(sprintf('[PosTerminal] Stripe injoignable (%s) : %s', $label, $e->getMessage()));
            throw new PosTerminalGatewayException('STRIPE_UNAVAILABLE', 'Stripe injoignable, réessayez', 503, null, $e);
        } catch (ApiErrorException $e) {
            $code = (string) ($e->getStripeCode() ?? '');
            $this->logger->warning(sprintf('[PosTerminal] Erreur Stripe (%s) %s : %s', $label, $code, $e->getMessage()));
            [$errorCode, $status, $message] = match ($code) {
                'terminal_reader_offline' => ['READER_OFFLINE', 409, 'Le terminal est hors ligne : vérifiez son alimentation et sa connexion'],
                'terminal_reader_busy' => ['READER_BUSY', 409, 'Le terminal est occupé par une autre opération'],
                'terminal_reader_timeout' => ['READER_TIMEOUT', 504, 'Le terminal n\'a pas répondu à temps, réessayez'],
                'intent_invalid_state' => ['PAYMENT_INVALID_STATE', 409, 'Ce paiement n\'est plus dans un état permettant cette opération'],
                'resource_missing' => ['STRIPE_NOT_FOUND', 404, 'Objet Stripe introuvable'],
                'charge_already_refunded' => ['ALREADY_REFUNDED', 409, 'Paiement déjà remboursé'],
                default => ['STRIPE_ERROR', 502, 'Erreur Stripe : ' . $e->getMessage()],
            };
            throw new PosTerminalGatewayException($errorCode, $message, $status, $code !== '' ? $code : null, $e);
        }
    }

    public static function intent(PaymentIntent $pi): array
    {
        $charge = is_object($pi->latest_charge) ? $pi->latest_charge : null;
        $type = $charge?->payment_method_details?->type;
        $details = $type !== null ? ($charge->payment_method_details->{$type} ?? null) : null;
        $error = $pi->last_payment_error;

        return [
            'id' => $pi->id, 'status' => $pi->status, 'amount' => (int) $pi->amount, 'amountReceived' => (int) ($pi->amount_received ?? 0),
            'captured' => $pi->status === 'succeeded',
            'paymentMethodType' => $type, 'cardBrand' => $details?->brand ?? null, 'last4' => $details?->last4 ?? null,
            'failureCode' => $error ? ($error->decline_code ?? $error->code ?? null) : null, 'failureMessage' => $error?->message ?? null,
            'applicationFee' => (int) ($pi->application_fee_amount ?? 0),
            // F13/F16 : secret pour le kit (confirmation par l'application) et charge pour les remboursements en personne
            'clientSecret' => $pi->client_secret ?? null, 'chargeId' => $charge?->id ?? (is_string($pi->latest_charge ?? null) ? $pi->latest_charge : null),
        ];
    }

    public static function reader(Reader $reader): array
    {
        $action = $reader->action;
        $actionType = $action?->type;
        $detail = $actionType !== null ? ($action->{$actionType} ?? null) : null;
        $intent = $detail?->payment_intent ?? null;
        $refund = $detail?->refund ?? null;

        return [
            'id' => $reader->id, 'label' => (string) $reader->label, 'deviceType' => (string) $reader->device_type,
            'simulated' => str_starts_with((string) $reader->device_type, 'simulated'), 'status' => (string) ($reader->status ?? 'offline'),
            'action' => $action ? [
                'type' => $actionType, 'status' => $action->status, 'failureCode' => $action->failure_code ?? null, 'failureMessage' => $action->failure_message ?? null,
                'paymentIntent' => is_object($intent) ? $intent->id : $intent, 'refund' => is_object($refund) ? $refund->id : $refund,
            ] : null,
        ];
    }

    public static function refund(Refund $refund): array
    {
        $intent = $refund->payment_intent ?? null;

        return ['id' => $refund->id, 'status' => (string) $refund->status, 'amount' => (int) $refund->amount, 'failureReason' => $refund->failure_reason ?? null,
            'paymentIntent' => is_object($intent) ? $intent->id : $intent, 'created' => (int) ($refund->created ?? 0)];
    }

    /** Options du compte du mode de la caisse du site (null : aucun compte actif dans ce mode) */
    private function accountOptions(): ?array
    {
        return $this->accounts->accountOptions($this->accounts->mode(StripeAccountResolver::CHANNEL_POS));
    }
}
