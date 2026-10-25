<?php

namespace App\UseCase\PosTerminalUseCase;

use App\Entity\PosTerminalPayment;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosTerminalService\PosTerminalPaymentService;

/** Terminal de paiement Stripe de la caisse (C4, 23/10/2026) : validation des corps et orchestration pour /api/pos/terminal */
class PosTerminalUseCase
{
    public function __construct(private readonly PosTerminalPaymentService $payments, private readonly CashRegisterResolver $registers)
    {
    }

    public function status(mixed $cashRegisterId): array
    {
        return $this->payments->status($this->registers->resolve(is_numeric($cashRegisterId) ? (int) $cashRegisterId : null));
    }

    /** {cashRegisterId?, amount (cents, part carte), items[], otherPayments?: [{type, amount (cents)}]} */
    public function create(User $user, array $body, ?string $posKey): PosTerminalPayment
    {
        $errors = [];
        if (!is_numeric($body['amount'] ?? null) || (int) $body['amount'] <= 0 || (float) $body['amount'] !== (float) (int) $body['amount']) {
            $errors[] = ['path' => 'amount', 'message' => 'entier positif en cents attendu'];
        }
        // Vente d'une carte-cadeau (complément F9 / C4) : giftCardAmount (cents) à la place de items, exactement l'un des deux
        $giftCard = array_key_exists('giftCardAmount', $body) && $body['giftCardAmount'] !== null;
        $hasItems = !empty($body['items']) && is_array($body['items']);
        if ($giftCard && $hasItems) {
            $errors[] = ['path' => 'giftCardAmount', 'message' => 'items ou giftCardAmount, pas les deux'];
        } elseif ($giftCard) {
            if (!is_numeric($body['giftCardAmount']) || (int) $body['giftCardAmount'] <= 0 || (float) $body['giftCardAmount'] !== (float) (int) $body['giftCardAmount']) {
                $errors[] = ['path' => 'giftCardAmount', 'message' => 'entier positif en cents attendu (montant de la carte-cadeau)'];
            }
        } elseif (!$hasItems) {
            $errors[] = ['path' => 'items', 'message' => 'au moins un article attendu (ou giftCardAmount pour une carte-cadeau)'];
        }
        $others = [];
        foreach (is_array($body['otherPayments'] ?? null) ? $body['otherPayments'] : [] as $i => $other) {
            if (!is_array($other) || !is_numeric($other['type'] ?? null) || !is_numeric($other['amount'] ?? null) || (int) $other['amount'] < 0 || (int) $other['type'] === 1) {
                $errors[] = ['path' => "otherPayments[$i]", 'message' => '{type (2 espèces, 3…), amount (cents ≥ 0)} attendu, jamais type 1'];
                continue;
            }
            $others[] = ['type' => (int) $other['type'], 'amount' => (int) $other['amount']];
        }
        if ($errors) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Corps invalide', 422, $errors);
        }

        $reader = is_array($body['reader'] ?? null) ? array_intersect_key($body['reader'], array_flip(['id', 'label', 'type'])) : null; // kit (F13/F16) : lecteur déclaré par l'application, journal seulement

        return $this->payments->start($user, is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, $hasItems ? $body['items'] : [], $others, (int) $body['amount'], $posKey, is_array($body['ticketDiscount'] ?? null) ? $body['ticketDiscount'] : null, $reader, $giftCard ? (int) $body['giftCardAmount'] : null);
    }

    public function get(int $id, ?User $user = null): PosTerminalPayment
    {
        $payment = $this->payments->find($id);
        $this->payments->refresh($payment, $user);

        return $this->payments->refreshRefund($payment, $user);
    }

    public function cancel(int $id, User $user): PosTerminalPayment
    {
        return $this->payments->cancel($this->payments->find($id), $user);
    }

    /** {amount?} cents, partiel possible ; Interac : présenté sur le lecteur, suivi par GET */
    public function refund(int $id, array $body, User $user): PosTerminalPayment
    {
        if (array_key_exists('amount', $body) && $body['amount'] !== null && (!is_numeric($body['amount']) || (int) $body['amount'] <= 0)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Corps invalide', 422, [['path' => 'amount', 'message' => 'entier positif en cents attendu']]);
        }

        return $this->payments->startRefund($this->payments->find($id), isset($body['amount']) ? (int) $body['amount'] : null, $user);
    }

    /** Paiements réussis sans vente (responsable) */
    public function orphans(mixed $cashRegisterId): array
    {
        $register = is_numeric($cashRegisterId) ? $this->registers->resolve((int) $cashRegisterId) : null;

        return array_map($this->payments->toArray(...), $this->payments->orphans($register));
    }

    /** {type: card_present|interac_present, cardNumber?} : carte simulée (clé de test, lecteur simulé) */
    public function simulate(int $id, array $body, User $user): PosTerminalPayment
    {
        $type = is_string($body['type'] ?? null) ? $body['type'] : 'card_present';
        $number = isset($body['cardNumber']) && is_scalar($body['cardNumber']) ? (string) $body['cardNumber'] : null;

        return $this->payments->simulatePresent($this->payments->find($id), $type, $number, $user);
    }

    public function toArray(PosTerminalPayment $payment): array
    {
        return $this->payments->toArray($payment);
    }

    /** POST /api/pos/terminal/connection-token {cashRegisterId?} (kit Stripe Terminal, F13/F16) */
    public function connectionToken(User $user, array $body): array
    {
        return $this->payments->connectionToken($user, is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null);
    }

    /** POST /api/pos/terminal/payments/{id}/refund/confirm {stripeRefundId} (kit : remboursement Interac présenté sur le lecteur) */
    public function confirmRefund(int $id, array $body, User $user): PosTerminalPayment
    {
        $refundId = $body['stripeRefundId'] ?? null;
        if (!is_string($refundId) || !preg_match('/^re_[A-Za-z0-9_]{6,}$/', $refundId)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Corps invalide', 422, [['path' => 'stripeRefundId', 'message' => 'identifiant de remboursement Stripe (re_…) attendu']]);
        }

        return $this->payments->confirmRefund($this->payments->find($id), $refundId, $user);
    }
}
