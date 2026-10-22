<?php

namespace App\Tests\Fake;

use App\Services\PosTerminalService\PosTerminalGatewayException;
use App\Services\PosTerminalService\PosTerminalGatewayInterface;

/**
 * Stripe Terminal simulé, en mémoire, aucun appel réseau. Le lecteur inconnu est créé à la volée (simulé, en ligne) ;
 * presentPaymentMethod() joue la carte du client : 4242… acceptée (carte autorisée, à capturer), 4506… Interac (débitée
 * aussitôt), 4000 0000 0000 0002 refusée, 4000 0000 0000 9995 fonds insuffisants. Réglages de scénario en statiques.
 */
final class FakeTerminalGateway implements PosTerminalGatewayInterface
{
    public static array $intents = [];
    public static array $readers = [];
    public static array $refunds = [];
    /** @var list<string> lecteurs hors ligne */
    public static array $offline = [];
    /** Lecteur en pleine autorisation : cancel_action refusé */
    public static array $authorizing = [];
    /** @var ?callable appelé juste après une capture réussie (simule une panne de base après Stripe) */
    public static $afterCapture = null;
    public static bool $live = false;
    public static array $idempotencyKeys = [];

    public static function reset(): void
    {
        self::$intents = self::$readers = self::$refunds = self::$offline = self::$authorizing = self::$idempotencyKeys = [];
        self::$afterCapture = null;
        self::$live = false;
    }

    public function createPaymentIntent(int $amount, string $currency, array $paymentMethodTypes, int $applicationFee, array $metadata, string $idempotencyKey): array
    {
        if (isset(self::$idempotencyKeys[$idempotencyKey])) {
            return $this->intent(self::$idempotencyKeys[$idempotencyKey]);
        }
        $id = 'pi_test_' . bin2hex(random_bytes(6));
        self::$intents[$id] = ['id' => $id, 'status' => 'requires_payment_method', 'amount' => $amount, 'amount_received' => 0, 'currency' => strtolower($currency),
            'types' => $paymentMethodTypes, 'fee' => $applicationFee, 'metadata' => $metadata, 'type' => null, 'brand' => null, 'last4' => null, 'failure' => null, 'failureMessage' => null];
        self::$idempotencyKeys[$idempotencyKey] = $id;

        return $this->intent($id);
    }

    public function retrievePaymentIntent(string $id): ?array
    {
        return isset(self::$intents[$id]) ? $this->intent($id) : null;
    }

    public function capturePaymentIntent(string $id, int $applicationFee, string $idempotencyKey): array
    {
        $pi = &self::$intents[$id];
        if (isset(self::$idempotencyKeys[$idempotencyKey]) && $pi['status'] === 'succeeded') {
            return $this->intent($id);
        }
        if ($pi['status'] !== 'requires_capture') {
            throw new PosTerminalGatewayException('PAYMENT_INVALID_STATE', 'Ce paiement n\'est plus dans un état permettant cette opération', 409, 'intent_invalid_state');
        }
        $pi['status'] = 'succeeded';
        $pi['amount_received'] = $pi['amount'];
        $pi['fee'] = $applicationFee;
        self::$idempotencyKeys[$idempotencyKey] = $id;
        if (self::$afterCapture) {
            (self::$afterCapture)($id);
        }

        return $this->intent($id);
    }

    public function cancelPaymentIntent(string $id, string $idempotencyKey): array
    {
        $pi = &self::$intents[$id];
        if ($pi['status'] === 'succeeded') {
            throw new PosTerminalGatewayException('PAYMENT_INVALID_STATE', 'Paiement déjà débité', 409, 'intent_invalid_state');
        }
        $pi['status'] = 'canceled';
        foreach (self::$readers as &$reader) {
            if (($reader['action']['paymentIntent'] ?? null) === $id && $reader['action']['status'] === 'in_progress') {
                $reader['action'] = null;
            }
        }

        return $this->intent($id);
    }

    public function processOnReader(string $readerId, string $paymentIntentId, bool $customerCancellation, string $idempotencyKey): array
    {
        $reader = &$this->reader($readerId);
        if (in_array($readerId, self::$offline, true)) {
            throw new PosTerminalGatewayException('READER_OFFLINE', 'Le terminal est hors ligne', 409, 'terminal_reader_offline');
        }
        if (($reader['action']['status'] ?? null) === 'in_progress' && ($reader['action']['paymentIntent'] ?? null) !== $paymentIntentId) {
            throw new PosTerminalGatewayException('READER_BUSY', 'Le terminal est occupé', 409, 'terminal_reader_busy');
        }
        if ((self::$intents[$paymentIntentId]['status'] ?? null) !== 'requires_payment_method') {
            throw new PosTerminalGatewayException('PAYMENT_INVALID_STATE', 'État invalide', 409, 'intent_invalid_state');
        }
        $reader['action'] = ['type' => 'process_payment_intent', 'status' => 'in_progress', 'failureCode' => null, 'failureMessage' => null, 'paymentIntent' => $paymentIntentId, 'refund' => null];

        return $reader;
    }

    public function retrieveReader(string $readerId): ?array
    {
        $reader = $this->reader($readerId);
        $reader['status'] = in_array($readerId, self::$offline, true) ? 'offline' : 'online';

        return $reader;
    }

    public function cancelReaderAction(string $readerId): array
    {
        $reader = &$this->reader($readerId);
        if (in_array($readerId, self::$authorizing, true)) {
            throw new PosTerminalGatewayException('READER_BUSY', 'Autorisation en cours', 409, 'terminal_reader_busy');
        }
        $reader['action'] = null;

        return $reader;
    }

    public function setReaderDisplay(string $readerId, string $currency, array $lines, int $tax, int $total): void
    {
        $this->reader($readerId)['display'] = ['lines' => $lines, 'total' => $total];
    }

    public function refundOnline(string $paymentIntentId, int $amount, string $idempotencyKey): array
    {
        if (isset(self::$idempotencyKeys[$idempotencyKey])) {
            return self::$refunds[self::$idempotencyKeys[$idempotencyKey]];
        }
        $pi = self::$intents[$paymentIntentId] ?? throw new PosTerminalGatewayException('STRIPE_NOT_FOUND', 'Intention inconnue', 404, 'resource_missing');
        if ($pi['status'] !== 'succeeded') {
            throw new PosTerminalGatewayException('PAYMENT_INVALID_STATE', 'Paiement non débité', 409, 'intent_invalid_state');
        }
        if ($pi['type'] === 'interac_present') {
            throw new PosTerminalGatewayException('STRIPE_ERROR', 'Interac refunds must be processed in person', 502, 'payment_method_unsupported');
        }
        $id = 're_test_' . bin2hex(random_bytes(6));
        self::$refunds[$id] = ['id' => $id, 'status' => 'succeeded', 'amount' => $amount, 'failureReason' => null, 'paymentIntent' => $paymentIntentId];
        self::$intents[$paymentIntentId]['refunded'] = ($pi['refunded'] ?? 0) + $amount;
        self::$idempotencyKeys[$idempotencyKey] = $id;

        return self::$refunds[$id];
    }

    public function refundOnReader(string $readerId, string $paymentIntentId, int $amount, string $idempotencyKey): array
    {
        $reader = &$this->reader($readerId);
        if (in_array($readerId, self::$offline, true)) {
            throw new PosTerminalGatewayException('READER_OFFLINE', 'Le terminal est hors ligne', 409, 'terminal_reader_offline');
        }
        $reader['action'] = ['type' => 'refund_payment', 'status' => 'in_progress', 'failureCode' => null, 'failureMessage' => null, 'paymentIntent' => $paymentIntentId, 'refund' => null, 'amount' => $amount];

        return $reader;
    }

    public function retrieveRefund(string $refundId): ?array
    {
        return self::$refunds[$refundId] ?? null;
    }

    public function createLocation(string $displayName, array $address): string
    {
        return 'tml_test_' . bin2hex(random_bytes(4));
    }

    public function listReaders(?string $locationId): array
    {
        return array_values(self::$readers);
    }

    public function createReader(string $registrationCode, string $label, string $locationId): array
    {
        if ($registrationCode !== 'simulated-wpe' && !preg_match('/^[a-z]+-[a-z]+-[a-z]+$/', $registrationCode)) {
            throw new PosTerminalGatewayException('STRIPE_ERROR', 'Code d\'appairage invalide', 502, 'invalid_request');
        }
        $id = 'tmr_test_' . bin2hex(random_bytes(4));
        $simulated = $registrationCode === 'simulated-wpe';
        self::$readers[$id] = ['id' => $id, 'label' => $label, 'deviceType' => $simulated ? 'simulated_wisepos_e' : 'bbpos_wisepos_e', 'simulated' => $simulated, 'status' => 'online', 'action' => null];

        return self::$readers[$id];
    }

    public function presentPaymentMethod(string $readerId, string $type, ?string $cardNumber): array
    {
        if (self::$live) {
            throw new PosTerminalGatewayException('SIMULATION_FORBIDDEN', 'Clé de production', 403);
        }
        $reader = &$this->reader($readerId);
        $action = &$reader['action'];
        if (($action['status'] ?? null) !== 'in_progress') {
            throw new PosTerminalGatewayException('STRIPE_ERROR', 'Aucune action en cours sur le lecteur', 502);
        }
        $number = preg_replace('/\D/', '', (string) ($cardNumber ?? ($type === 'interac_present' ? '4506445006931933' : '4242424242424242')));
        $piId = $action['paymentIntent'];
        if ($action['type'] === 'refund_payment') {
            $id = 're_test_' . bin2hex(random_bytes(6));
            self::$refunds[$id] = ['id' => $id, 'status' => 'succeeded', 'amount' => $action['amount'], 'failureReason' => null, 'paymentIntent' => $piId];
            self::$intents[$piId]['refunded'] = (self::$intents[$piId]['refunded'] ?? 0) + $action['amount'];
            $action = ['type' => 'refund_payment', 'status' => 'succeeded', 'failureCode' => null, 'failureMessage' => null, 'paymentIntent' => $piId, 'refund' => $id];

            return $reader;
        }
        $pi = &self::$intents[$piId];
        $declines = ['4000000000000002' => ['card_declined', 'generic_decline', 'Your card was declined.'], '4000000000009995' => ['card_declined', 'insufficient_funds', 'Your card has insufficient funds.'],
            '4000000000000069' => ['expired_card', 'expired_card', 'Your card has expired.']];
        if (isset($declines[$number])) {
            [$code, $decline, $message] = $declines[$number];
            $pi['failure'] = $decline;
            $pi['failureMessage'] = $message;
            $action = ['type' => 'process_payment_intent', 'status' => 'failed', 'failureCode' => $code, 'failureMessage' => $message, 'paymentIntent' => $piId, 'refund' => null];

            return $reader;
        }
        $interac = $type === 'interac_present' || $number === '4506445006931933';
        if ($interac && !in_array('interac_present', $pi['types'], true)) {
            $pi['failure'] = 'card_not_supported';
            $action = ['type' => 'process_payment_intent', 'status' => 'failed', 'failureCode' => 'card_declined', 'failureMessage' => 'Interac not accepted', 'paymentIntent' => $piId, 'refund' => null];

            return $reader;
        }
        $pi['type'] = $interac ? 'interac_present' : 'card_present';
        $pi['brand'] = $interac ? 'interac' : (str_starts_with($number, '5') ? 'mastercard' : 'visa');
        $pi['last4'] = substr($number, -4);
        $pi['status'] = $interac ? 'succeeded' : 'requires_capture';
        $pi['amount_received'] = $interac ? $pi['amount'] : 0;
        $action = ['type' => 'process_payment_intent', 'status' => 'succeeded', 'failureCode' => null, 'failureMessage' => null, 'paymentIntent' => $piId, 'refund' => null];

        return $reader;
    }

    public function isLiveMode(): bool
    {
        return self::$live;
    }

    private function &reader(string $readerId): array
    {
        if (!isset(self::$readers[$readerId])) {
            self::$readers[$readerId] = ['id' => $readerId, 'label' => 'Lecteur de test', 'deviceType' => 'simulated_wisepos_e', 'simulated' => true, 'status' => 'online', 'action' => null];
        }

        return self::$readers[$readerId];
    }

    private function intent(string $id): array
    {
        $pi = self::$intents[$id];

        return ['id' => $id, 'status' => $pi['status'], 'amount' => $pi['amount'], 'amountReceived' => $pi['amount_received'], 'captured' => $pi['status'] === 'succeeded',
            'paymentMethodType' => $pi['type'], 'cardBrand' => $pi['brand'], 'last4' => $pi['last4'], 'failureCode' => $pi['failure'], 'failureMessage' => $pi['failureMessage'], 'applicationFee' => $pi['fee']];
    }
}
