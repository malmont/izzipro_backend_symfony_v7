<?php

namespace App\Services\PosTerminalService;

use App\Dto\CartQuoteInputDto;
use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\PosTerminalPayment;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\CaisseService\CashRoundingService;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosAuditService\PosTerminalService;
use App\Services\StripeService\StripeConnectSetupService;
use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Paiements par terminal Stripe de la caisse (C4, 23/10/2026), intégration pilotée par le serveur : la caisse demande le
 * paiement au backend, qui l'envoie au lecteur du poste et relit son état chez Stripe à chaque interrogation du POS.
 * Montant garanti par le devis du serveur (CartQuoteCalculator) ; carte de crédit autorisée puis capturée dans la
 * transaction de la vente ; Interac débité aussitôt ; chaque appel Stripe porte une clé d'idempotence dérivée de la clé
 * POS (création) ou du PaymentIntent (capture, annulation, remboursement) : un réessai ne débite ni ne rembourse deux
 * fois. Aucune donnée de carte au-delà de la marque et des 4 derniers chiffres.
 */
class PosTerminalPaymentService
{
    public const SIMULATION_TYPES = ['card_present', 'interac_present'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosTerminalGatewayInterface $gateway,
        private readonly PosTerminalSettingsService $settings,
        private readonly CashRegisterResolver $registers,
        private readonly CartQuoteCalculator $quotes,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly CashRoundingService $rounding,
        private readonly PosAuditService $audit,
        private readonly \App\Services\PosAuditService\PosApprovalService $approvals,
        private readonly PosTerminalService $posTerminals,
        private readonly StripeService $stripe,
        private readonly StripeConnectSetupService $connect,
        private readonly TenantConnectionManager $tenants,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------- état du terminal

    /** GET /api/pos/terminal : disponibilité du terminal du poste, lecteur, moyens acceptés, paiement en cours */
    public function status(CashRegister $register): array
    {
        $settings = $this->settings->settings();
        if (!$register->hasTerminal()) {
            return ['available' => false, 'cashRegisterId' => $register->getId(), 'reader' => null, 'paymentMethods' => [], 'currentPayment' => null, 'simulationAllowed' => false,
                'settings' => ['cardWaitSeconds' => $settings['cardWaitSeconds'], 'customerCancel' => $settings['customerCancel'], 'interac' => $settings['interac']]];
        }
        $readerStatus = 'offline';
        $error = null;
        try {
            $reader = $this->gateway->retrieveReader((string) $register->getStripeReaderId());
            $readerStatus = $reader ? $reader['status'] : 'offline';
            $error = $reader ? null : 'READER_NOT_FOUND';
        } catch (PosTerminalGatewayException $e) {
            $error = $e->errorCode;
        }
        $current = $this->repo()->findActiveOnReader((string) $register->getStripeReaderId());
        if ($current) {
            $current = $this->refresh($current);
        }

        return [
            'available' => true, 'cashRegisterId' => $register->getId(),
            'reader' => ['id' => $register->getStripeReaderId(), 'label' => $register->getStripeReaderLabel(), 'simulated' => $register->isStripeReaderSimulated(), 'status' => $readerStatus, 'error' => $error],
            'paymentMethods' => $this->settings->paymentMethodTypes(),
            'currentPayment' => $current && $current->isPending() && $current->getStatus() !== PosTerminalPayment::AUTHORIZED ? $this->toArray($current) : null,
            'simulationAllowed' => $register->isStripeReaderSimulated() && !$this->gateway->isLiveMode(),
            'settings' => ['cardWaitSeconds' => $settings['cardWaitSeconds'], 'customerCancel' => $settings['customerCancel'], 'interac' => $settings['interac']],
        ];
    }

    // ---------------------------------------------------------------- paiement

    /**
     * Crée le PaymentIntent (montant = part carte vérifiée contre le devis du serveur) et l'envoie au lecteur du poste.
     *
     * @param array $items articles de la vente (comme create-multi-payment)
     * @param list<array{type: int, amount: int}> $otherPayments autres parts (cents) : espèces (type 2), etc.
     * @param int $amount part carte en cents
     * @param ?string $posKey Idempotency-Key POS de la requête (clé Stripe de la création)
     */
    public function start(User $user, ?int $cashRegisterId, array $items, array $otherPayments, int $amount, ?string $posKey): PosTerminalPayment
    {
        $register = $this->registers->resolve($cashRegisterId);
        if (!$register->hasTerminal()) {
            throw new CashRegisterException('TERMINAL_UNAVAILABLE', sprintf('Aucun terminal de paiement sur le poste « %s »', $register->getName()), 409, [], ['cashRegisterId' => $register->getId()]);
        }
        $session = $this->registers->requireOpenSession($register->getId(), 409);
        if ($amount < 50) {
            throw new CashRegisterException('AMOUNT_TOO_SMALL', 'Montant carte inférieur au minimum accepté (0,50)', 400, [], ['amount' => $amount]);
        }
        // Devis du serveur : la part carte doit compléter exactement les autres parts (arrondi des espèces admis)
        try {
            $quote = $this->quotes->quote(CartQuoteInputDto::fromArray(['items' => $items, 'shippingAddress' => $this->taxAddress->forStore()]));
        } catch (CartQuoteException $e) {
            throw new CashRegisterException('QUOTE_INVALID', $e->getMessage(), 400);
        }
        $total = (int) $quote['total'];
        $cash = $nonCash = 0;
        foreach ($otherPayments as $other) {
            (int) ($other['type'] ?? 0) === 2 ? $cash += (int) $other['amount'] : $nonCash += (int) $other['amount'];
        }
        try {
            $this->rounding->checkCounterPayment($total, $cash, $amount + $nonCash);
        } catch (CashRegisterException $e) {
            throw new CashRegisterException('AMOUNT_MISMATCH', 'La part carte ne correspond pas au devis du serveur', 422, [], ['quoteTotal' => $total, 'otherPayments' => $cash + $nonCash, 'expectedCard' => $total - $cash - $nonCash, 'amount' => $amount]);
        }
        $readerId = (string) $register->getStripeReaderId();
        // Une seule opération à la fois sur un lecteur
        $active = $this->repo()->findActiveOnReader($readerId);
        if ($active && $this->refresh($active)->isPending() && $active->getStatus() !== PosTerminalPayment::AUTHORIZED) {
            throw new CashRegisterException('READER_BUSY', 'Un paiement est déjà en cours sur ce terminal', 409, [], ['paymentId' => $active->getId()]);
        }
        $reader = $this->guard(fn () => $this->gateway->retrieveReader($readerId));
        if ($reader === null) {
            throw new CashRegisterException('READER_NOT_FOUND', 'Le lecteur du poste n\'existe plus chez Stripe : à réappairer', 409);
        }
        if ($reader['status'] !== 'online') {
            throw new CashRegisterException('READER_OFFLINE', 'Le terminal est hors ligne : vérifiez son alimentation et sa connexion', 409);
        }
        $settings = $this->settings->settings();
        $em = $this->emProvider->getEntityManager();
        $key = $posKey ?? self::uuid();
        $payment = (new PosTerminalPayment())->setCashRegister($register)->setCaisseId($session->getId())->setUserId((int) $user->getId())
            ->setTerminalId($this->posTerminals->current()['id'] ?? null)->setReaderId($readerId)->setAmount($amount)->setCurrency($settings['currency'])
            ->setQuoteTotal($total)->setOtherPayments($cash + $nonCash)->setQuoteHash(hash('sha256', json_encode([$items, $otherPayments])))
            ->setIdempotencyKey($key)->setStripePaymentIntentId('pending:' . $key)->setApplicationFee($this->settings->fee($amount, 'interac_present'));
        $em->persist($payment);
        $em->flush();
        try {
            $intent = $this->gateway->createPaymentIntent($amount, $settings['currency'], $this->settings->paymentMethodTypes(), $payment->getApplicationFee(),
                ['store_code' => (string) $this->tenants->getCurrentTenantCode(), 'pos_terminal' => '1', 'pos_payment_id' => (string) $payment->getId(), 'cash_register_id' => (string) $register->getId()], $key);
        } catch (PosTerminalGatewayException $e) {
            $payment->setStatus(PosTerminalPayment::FAILED)->setFailureCode($e->errorCode)->setFailureMessage($e->getMessage());
            $em->flush();
            throw $this->translate($e);
        }
        $payment->setStripePaymentIntentId($intent['id']);
        $em->flush();
        if ($settings['cartDisplay']) {
            try {
                $lines = array_map(fn (array $l) => ['description' => mb_substr((string) ($l['name'] ?? $l['label'] ?? 'Article'), 0, 60), 'amount' => (int) ($l['unitPrice'] ?? $l['price'] ?? 0), 'quantity' => (int) ($l['quantity'] ?? 1)], $quote['lines'] ?? []);
                $this->gateway->setReaderDisplay($readerId, $settings['currency'], $lines, (int) array_sum(array_map(fn ($t) => (int) ($t['amount'] ?? 0), $quote['taxes'] ?? [])), $total);
            } catch (PosTerminalGatewayException $e) {
                $this->logger->info('[PosTerminal] affichage du panier impossible : ' . $e->getMessage());
            }
        }
        try {
            $this->gateway->processOnReader($readerId, $intent['id'], $settings['customerCancel'], 'pos-process-' . $intent['id']);
        } catch (PosTerminalGatewayException $e) {
            $this->cancelIntentQuietly($payment);
            $payment->setStatus(PosTerminalPayment::CANCELED)->setFailureCode($e->errorCode)->setFailureMessage($e->getMessage());
            $em->flush();
            throw $this->translate($e, ['paymentId' => $payment->getId()]);
        }
        $payment->setStatus(PosTerminalPayment::WAITING_FOR_CARD);
        $em->flush();
        $this->record(PosAuditService::CARD_PAYMENT_STARTED, $payment, $user);

        return $payment;
    }

    /** Relit Stripe (intention et lecteur) et met l'enregistrement à jour : refus, délai dépassé, autorisation expirée */
    public function refresh(PosTerminalPayment $payment, ?User $user = null): PosTerminalPayment
    {
        if (!$payment->isPending() || str_starts_with($payment->getStripePaymentIntentId(), 'pending:')) {
            if ($payment->getStatus() === PosTerminalPayment::CREATED && $payment->getCreatedAt() < new \DateTimeImmutable('-5 minutes')) {
                $payment->setStatus(PosTerminalPayment::FAILED)->setFailureCode('intent_not_created'); // création jamais aboutie
                $this->emProvider->getEntityManager()->flush();
            }

            return $payment;
        }
        $em = $this->emProvider->getEntityManager();
        $before = $payment->getStatus();
        $settings = $this->settings->settings();
        try {
            $intent = $this->gateway->retrievePaymentIntent($payment->getStripePaymentIntentId());
            if ($intent === null) {
                $payment->setStatus(PosTerminalPayment::FAILED)->setFailureCode('intent_missing')->setFailureMessage('Intention de paiement introuvable chez Stripe');
                $em->flush();

                return $payment;
            }
            switch ($intent['status']) {
                case 'succeeded':
                    $this->applyCard($payment, $intent)->setStatus(PosTerminalPayment::CAPTURED);
                    break;
                case 'requires_capture':
                    $this->applyCard($payment, $intent);
                    if ($before !== PosTerminalPayment::AUTHORIZED) {
                        $payment->setStatus(PosTerminalPayment::AUTHORIZED);
                    } elseif ($payment->getOrderId() === null && $payment->getUpdatedAt() < new \DateTimeImmutable('-' . $settings['authCancelMinutes'] . ' minutes')) {
                        // Autorisation jamais suivie d'une vente : libérée (Stripe l'expirerait sous 2 jours)
                        $this->gateway->cancelPaymentIntent($payment->getStripePaymentIntentId(), 'pos-cancel-' . $payment->getStripePaymentIntentId());
                        $payment->setStatus(PosTerminalPayment::CANCELED)->setFailureCode('authorization_expired')->setFailureMessage('Autorisation non suivie d\'une vente, annulée');
                    }
                    break;
                case 'canceled':
                    $payment->setStatus(PosTerminalPayment::CANCELED);
                    $payment->getFailureCode() ?? $payment->setFailureCode('canceled');
                    break;
                default: // requires_payment_method, requires_confirmation, processing : état du lecteur
                    $reader = $this->gateway->retrieveReader($payment->getReaderId());
                    $action = $reader['action'] ?? null;
                    $mine = $action !== null && ($action['paymentIntent'] ?? null) === $payment->getStripePaymentIntentId() && $action['type'] === 'process_payment_intent';
                    if ($mine && $action['status'] === 'failed') {
                        $payment->setStatus(PosTerminalPayment::FAILED)->setFailureCode($intent['failureCode'] ?? $action['failureCode'] ?? 'card_declined')
                            ->setFailureMessage($intent['failureMessage'] ?? $action['failureMessage']);
                        $this->cancelIntentQuietly($payment);
                    } elseif ($payment->getCreatedAt() < new \DateTimeImmutable('-' . $settings['cardWaitSeconds'] . ' seconds')) {
                        // Carte jamais présentée dans le délai : action du lecteur et intention annulées
                        if ($mine && $action['status'] === 'in_progress') {
                            $this->gateway->cancelReaderAction($payment->getReaderId()); // READER_BUSY pendant une autorisation : on réessaiera
                        }
                        $this->gateway->cancelPaymentIntent($payment->getStripePaymentIntentId(), 'pos-cancel-' . $payment->getStripePaymentIntentId());
                        $payment->setStatus(PosTerminalPayment::CANCELED)->setFailureCode('timeout')->setFailureMessage('Aucune carte présentée dans le délai');
                    } elseif ($mine && $action['status'] === 'in_progress') {
                        $payment->setStatus($intent['status'] === 'requires_payment_method' ? PosTerminalPayment::WAITING_FOR_CARD : PosTerminalPayment::PROCESSING);
                    } elseif ($reader === null || ($reader['status'] ?? 'offline') !== 'online') {
                        $payment->setFailureCode('reader_offline')->setFailureMessage('Terminal hors ligne pendant le paiement');
                    }
            }
        } catch (PosTerminalGatewayException $e) {
            $this->logger->warning(sprintf('[PosTerminal] relecture du paiement %d : %s', $payment->getId(), $e->getMessage()));
        }
        $em->flush();
        $this->recordTransition($before, $payment, $user);

        return $payment;
    }

    /** Annulation par le caissier (client qui renonce) : action du lecteur et intention annulées ; paiement débité → remboursement */
    public function cancel(PosTerminalPayment $payment, User $user): PosTerminalPayment
    {
        $this->refresh($payment, $user);
        if ($payment->getStatus() === PosTerminalPayment::CAPTURED) {
            throw new CashRegisterException('PAYMENT_ALREADY_CAPTURED', 'Paiement déjà débité : passez par un remboursement', 409, [], ['paymentId' => $payment->getId()]);
        }
        if (!$payment->isPending()) {
            return $payment; // déjà annulé ou refusé : rejeu sans effet
        }
        if (in_array($payment->getStatus(), [PosTerminalPayment::WAITING_FOR_CARD, PosTerminalPayment::PROCESSING], true)) {
            $this->guard(fn () => $this->gateway->cancelReaderAction($payment->getReaderId())); // READER_BUSY (409) si une autorisation est en cours
        }
        if (!str_starts_with($payment->getStripePaymentIntentId(), 'pending:')) {
            $this->guard(fn () => $this->gateway->cancelPaymentIntent($payment->getStripePaymentIntentId(), 'pos-cancel-' . $payment->getStripePaymentIntentId()));
        }
        $payment->setStatus(PosTerminalPayment::CANCELED)->setFailureCode('canceled_by_cashier');
        $this->emProvider->getEntityManager()->flush();
        $this->record(PosAuditService::CARD_PAYMENT_CANCELED, $payment, $user);

        return $payment;
    }

    // ---------------------------------------------------------------- vente et annulation de vente

    /** Paiement utilisable pour une part carte d'une vente : réussi, même montant, même poste, jamais rattaché */
    public function verifyForSale(int|string $reference, int $amount, CashRegister $register): PosTerminalPayment
    {
        $payment = is_int($reference) || ctype_digit((string) $reference) ? $this->repo()->find((int) $reference) : $this->repo()->findByIntent((string) $reference);
        if (!$payment) {
            throw new CashRegisterException('TERMINAL_PAYMENT_NOT_FOUND', 'Paiement par terminal introuvable', 404, [], ['terminalPaymentId' => $reference]);
        }
        $this->refresh($payment);
        if ($payment->getOrderId() !== null) {
            throw new CashRegisterException('TERMINAL_PAYMENT_ALREADY_USED', 'Ce paiement est déjà rattaché à une vente', 409, [], ['terminalPaymentId' => $payment->getId(), 'orderId' => $payment->getOrderId()]);
        }
        if ($payment->getCashRegister()->getId() !== $register->getId()) {
            throw new CashRegisterException('TERMINAL_PAYMENT_WRONG_REGISTER', 'Paiement pris sur un autre poste', 409, [], ['terminalPaymentId' => $payment->getId(), 'cashRegisterId' => $payment->getCashRegister()->getId()]);
        }
        if (!in_array($payment->getStatus(), [PosTerminalPayment::AUTHORIZED, PosTerminalPayment::CAPTURED], true)) {
            throw new CashRegisterException('TERMINAL_PAYMENT_NOT_SUCCEEDED', 'Le paiement par terminal n\'a pas réussi', 402, [], ['terminalPaymentId' => $payment->getId(), 'status' => $this->publicStatus($payment), 'failureCode' => $payment->getFailureCode()]);
        }
        if ($payment->getAmount() !== $amount) {
            throw new CashRegisterException('TERMINAL_PAYMENT_AMOUNT_MISMATCH', 'Le montant du paiement par terminal diffère de la part carte de la vente', 400, [], ['terminalPaymentId' => $payment->getId(), 'paid' => $payment->getAmount(), 'expected' => $amount]);
        }

        return $payment;
    }

    /** Dans la transaction de la vente : capture de l'autorisation (carte de crédit) et rattachement à la commande */
    public function captureForSale(PosTerminalPayment $payment, Order $order): PosTerminalPayment
    {
        if ($payment->getStatus() === PosTerminalPayment::AUTHORIZED) {
            $fee = $this->settings->fee($payment->getAmount(), 'card_present');
            try {
                $intent = $this->gateway->capturePaymentIntent($payment->getStripePaymentIntentId(), $fee, 'pos-capture-' . $payment->getStripePaymentIntentId());
            } catch (PosTerminalGatewayException $e) {
                throw new CashRegisterException('TERMINAL_CAPTURE_FAILED', 'Capture du paiement carte refusée par Stripe : ' . $e->getMessage(), $e->status >= 500 ? 502 : 402, [], ['terminalPaymentId' => $payment->getId(), 'stripeCode' => $e->stripeCode]);
            }
            if (!$intent['captured']) {
                throw new CashRegisterException('TERMINAL_CAPTURE_FAILED', 'Capture non confirmée par Stripe', 502, [], ['terminalPaymentId' => $payment->getId(), 'status' => $intent['status']]);
            }
            $this->applyCard($payment, $intent)->setApplicationFee($fee)->setStatus(PosTerminalPayment::CAPTURED);
        }
        $payment->attachOrder((int) $order->getId());

        return $payment;
    }

    /** Remboursement d'une vente annulée (dans sa transaction) : en ligne pour une carte de crédit ; Interac exige le lecteur */
    public function refundForCancel(PosTerminalPayment $payment, ?string $terminalRefundId, ?User $user): void
    {
        if ($payment->isInterac()) {
            if ($payment->getRefundStatus() === PosTerminalPayment::REFUND_SUCCEEDED && $payment->getRefundId() !== null && $payment->getRefundId() === $terminalRefundId) {
                return; // remboursement déjà fait sur le lecteur (flux en deux temps)
            }
            throw new CashRegisterException('TERMINAL_REFUND_REQUIRED', 'Paiement Interac : le remboursement doit être présenté sur le terminal (POST /api/pos/terminal/payments/{id}/refund) avant d\'annuler la vente', 409, [],
                ['terminalPaymentId' => $payment->getId(), 'amount' => $payment->getAmount(), 'paymentMethodType' => 'interac_present', 'refund' => $this->refundArray($payment)]);
        }
        if ($payment->getRefundStatus() === PosTerminalPayment::REFUND_SUCCEEDED) {
            return;
        }
        $this->refundOnline($payment, $payment->getAmount(), $user);
    }

    /** POST /api/pos/terminal/payments/{id}/refund : remboursement d'un paiement débité (paiement sans vente, Interac avant annulation) */
    public function startRefund(PosTerminalPayment $payment, ?int $amount, User $user): PosTerminalPayment
    {
        // Caissier : validation d'un responsable (C3) comme pour l'annulation d'une vente, action « refund », contexte
        // {orderId} du paiement rattaché, sinon {orderId: 0, terminalPaymentId} pour un paiement sans vente
        $this->approvals->requireFor(\App\Services\PosAuditService\PosApprovalService::REFUND, $user,
            $payment->getOrderId() !== null ? ['orderId' => $payment->getOrderId()] : ['orderId' => 0, 'terminalPaymentId' => (int) $payment->getId()], $amount ?? $payment->getAmount());
        $this->refresh($payment, $user);
        if ($payment->getStatus() === PosTerminalPayment::AUTHORIZED) {
            // Autorisation jamais capturée : libérée, rien à rembourser
            $this->guard(fn () => $this->gateway->cancelPaymentIntent($payment->getStripePaymentIntentId(), 'pos-cancel-' . $payment->getStripePaymentIntentId()));
            $payment->setStatus(PosTerminalPayment::CANCELED)->setFailureCode('voided_by_manager');
            $this->emProvider->getEntityManager()->flush();
            $this->record(PosAuditService::CARD_PAYMENT_CANCELED, $payment, $user);

            return $payment;
        }
        if ($payment->getStatus() !== PosTerminalPayment::CAPTURED) {
            throw new CashRegisterException('PAYMENT_NOT_CAPTURED', 'Ce paiement n\'a pas été débité : rien à rembourser', 409, [], ['status' => $this->publicStatus($payment)]);
        }
        if (in_array($payment->getRefundStatus(), [PosTerminalPayment::REFUND_WAITING, PosTerminalPayment::REFUND_SUCCEEDED], true)) {
            return $this->refreshRefund($payment, $user); // rejeu
        }
        $amount ??= $payment->getAmount();
        if ($amount <= 0 || $amount > $payment->getAmount()) {
            throw new CashRegisterException('REFUND_AMOUNT_INVALID', 'Montant de remboursement invalide', 400, [], ['max' => $payment->getAmount()]);
        }
        if ($payment->isInterac()) {
            $this->guard(fn () => $this->gateway->refundOnReader($payment->getReaderId(), $payment->getStripePaymentIntentId(), $amount, 'pos-refund-' . $payment->getStripePaymentIntentId() . '-' . $amount));
            $payment->setRefundStatus(PosTerminalPayment::REFUND_WAITING)->setRefundAmount($amount)->setRefundFailureMessage(null);
            $this->emProvider->getEntityManager()->flush();

            return $payment;
        }
        $this->refundOnline($payment, $amount, $user);

        return $payment;
    }

    /** Suivi d'un remboursement présenté sur le lecteur (Interac) */
    public function refreshRefund(PosTerminalPayment $payment, ?User $user = null): PosTerminalPayment
    {
        if ($payment->getRefundStatus() !== PosTerminalPayment::REFUND_WAITING) {
            return $payment;
        }
        try {
            $reader = $this->gateway->retrieveReader($payment->getReaderId());
            $action = $reader['action'] ?? null;
            $mine = $action !== null && $action['type'] === 'refund_payment' && ($action['paymentIntent'] ?? null) === $payment->getStripePaymentIntentId();
            if ($mine && $action['status'] === 'succeeded') {
                $payment->setRefundId($action['refund'])->setRefundStatus(PosTerminalPayment::REFUND_SUCCEEDED);
                $this->emProvider->getEntityManager()->flush();
                $this->record(PosAuditService::CARD_REFUND, $payment, $user, ['refundAmount' => $payment->getRefundAmount(), 'refundId' => $payment->getRefundId(), 'onReader' => true]);
            } elseif ($mine && $action['status'] === 'failed') {
                $payment->setRefundStatus(PosTerminalPayment::REFUND_FAILED)->setRefundFailureMessage($action['failureMessage'] ?? $action['failureCode']);
                $this->emProvider->getEntityManager()->flush();
            } elseif (!$mine || $payment->getUpdatedAt() < new \DateTimeImmutable('-' . $this->settings->settings()['cardWaitSeconds'] . ' seconds')) {
                if ($mine) {
                    $this->gateway->cancelReaderAction($payment->getReaderId());
                }
                if (!$mine || $payment->getUpdatedAt() < new \DateTimeImmutable('-' . $this->settings->settings()['cardWaitSeconds'] . ' seconds')) {
                    $payment->setRefundStatus(PosTerminalPayment::REFUND_FAILED)->setRefundFailureMessage('Aucune carte présentée dans le délai');
                    $this->emProvider->getEntityManager()->flush();
                }
            }
        } catch (PosTerminalGatewayException $e) {
            $this->logger->warning(sprintf('[PosTerminal] suivi du remboursement %d : %s', $payment->getId(), $e->getMessage()));
        }

        return $payment;
    }

    /** Paiements réussis sans vente au-delà du délai réglé (autorisations et débits) */
    public function orphans(?CashRegister $register = null): array
    {
        $list = $this->repo()->findOrphans($this->settings->settings()['orphanMinutes'], $register);
        foreach ($list as $payment) {
            $this->refresh($payment);
        }

        return array_values(array_filter($list, fn (PosTerminalPayment $p) => $p->getOrderId() === null && in_array($p->getStatus(), [PosTerminalPayment::CAPTURED, PosTerminalPayment::AUTHORIZED], true)));
    }

    /** Réconciliation (cron, ouverture et fermeture de session) : rien d'interrogé chez Stripe sans paiement en attente */
    public function reconcile(): array
    {
        $result = ['pending' => 0, 'authorized' => 0, 'captured' => 0, 'canceled' => 0, 'failed' => 0, 'refunds' => 0];
        if (!$this->repo()->hasPending() && !$this->repo()->findOneBy(['refundStatus' => PosTerminalPayment::REFUND_WAITING])) {
            return $result;
        }
        foreach ($this->repo()->findPending() as $payment) {
            $result['pending']++;
            $status = $this->refresh($payment)->getStatus();
            foreach (['authorized' => PosTerminalPayment::AUTHORIZED, 'captured' => PosTerminalPayment::CAPTURED, 'canceled' => PosTerminalPayment::CANCELED, 'failed' => PosTerminalPayment::FAILED] as $k => $s) {
                $status === $s && $result[$k]++;
            }
        }
        foreach ($this->repo()->findBy(['refundStatus' => PosTerminalPayment::REFUND_WAITING]) as $payment) {
            $result['refunds']++;
            $this->refreshRefund($payment);
        }

        return $result;
    }

    /** Clé de test et lecteur simulé seulement : le client présente une carte (aide de test Stripe) */
    public function simulatePresent(PosTerminalPayment $payment, string $type, ?string $cardNumber, User $user): PosTerminalPayment
    {
        if ($this->gateway->isLiveMode()) {
            throw new CashRegisterException('SIMULATION_FORBIDDEN', 'Présentation de carte simulée impossible avec une clé Stripe de production', 403);
        }
        if (!$payment->getCashRegister()->isStripeReaderSimulated()) {
            throw new CashRegisterException('SIMULATION_FORBIDDEN', 'Le lecteur de ce poste n\'est pas un lecteur simulé', 409);
        }
        if (!in_array($type, self::SIMULATION_TYPES, true)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'type : card_present ou interac_present attendu', 422, [['path' => 'type', 'message' => 'card_present ou interac_present']]);
        }
        $this->guard(fn () => $this->gateway->presentPaymentMethod($payment->getReaderId(), $type, $cardNumber));
        $this->record(PosAuditService::CARD_SIMULATED, $payment, $user, ['type' => $type, 'last4' => $cardNumber !== null ? substr(preg_replace('/\D/', '', $cardNumber), -4) : null]);
        $this->refresh($payment, $user);

        return $this->refreshRefund($payment, $user);
    }

    public function find(int $id): PosTerminalPayment
    {
        return $this->repo()->find($id) ?? throw new CashRegisterException('TERMINAL_PAYMENT_NOT_FOUND', 'Paiement par terminal introuvable', 404, [], ['paymentId' => $id]);
    }

    public function findByOrder(int $orderId): ?PosTerminalPayment
    {
        return $this->repo()->findOneByOrder($orderId);
    }

    // ---------------------------------------------------------------- configuration (EasyAdmin)

    /** Location Stripe Terminal du site, créée au besoin depuis l'adresse de l'entreprise */
    public function ensureLocation(): string
    {
        $config = $this->stripe->getStripeConfigForCurrentTenant();
        if ($this->stripe->accountOptions() === null) {
            throw new CashRegisterException('TERMINAL_UNAVAILABLE', 'Aucun compte Stripe actif sur ce site : connectez Stripe avant d\'ajouter un terminal', 409);
        }
        if ($config?->getTerminalLocationId()) {
            return $config->getTerminalLocationId();
        }
        $address = $this->connect->companyAddress();
        if ($address === null || empty($address['postal_code'])) {
            throw new CashRegisterException('STORE_ADDRESS_REQUIRED', 'Adresse complète de l\'entreprise requise (rue, ville, code postal, pays) pour créer l\'emplacement Terminal', 422);
        }
        $name = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getName() ?: (string) $this->tenants->getCurrentTenantCode();
        $id = $this->guard(fn () => $this->gateway->createLocation($name, $address));
        if ($config) {
            $config->setTerminalLocationId($id);
            $this->emProvider->getEntityManager()->flush();
        }

        return $id;
    }

    /** Appairage d'un lecteur réel par son code (affiché sur l'écran du lecteur) */
    public function pairReader(CashRegister $register, string $registrationCode, ?string $label = null): CashRegister
    {
        $location = $this->ensureLocation();
        $reader = $this->guard(fn () => $this->gateway->createReader($registrationCode, $label ?: 'Terminal ' . $register->getName(), $location));
        $this->attachReader($register, $reader);

        return $register;
    }

    /** Lecteur simulé (clé de test) : réutilise un lecteur simulé libre de la Location, sinon en crée un */
    public function createSimulatedReader(CashRegister $register): CashRegister
    {
        if ($this->gateway->isLiveMode()) {
            throw new CashRegisterException('SIMULATION_FORBIDDEN', 'Un lecteur simulé exige une clé Stripe de test', 403);
        }
        $location = $this->ensureLocation();
        $used = array_map(fn (CashRegister $r) => $r->getStripeReaderId(), $this->emProvider->getEntityManager()->getRepository(CashRegister::class)->findAll());
        $reader = null;
        foreach ($this->guard(fn () => $this->gateway->listReaders($location)) as $candidate) {
            if ($candidate['simulated'] && !in_array($candidate['id'], $used, true)) {
                $reader = $candidate;
                break;
            }
        }
        $reader ??= $this->guard(fn () => $this->gateway->createReader('simulated-wpe', 'Lecteur simulé ' . $register->getName(), $location));
        $this->attachReader($register, $reader);

        return $register;
    }

    public function detachReader(CashRegister $register): CashRegister
    {
        $before = ['readerId' => $register->getStripeReaderId(), 'label' => $register->getStripeReaderLabel()];
        $register->setStripeReaderId(null)->setStripeReaderLabel(null)->setStripeReaderSimulated(false)->setTerminalEnabled(false);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::TERMINAL_CONFIGURED, ['action' => 'detach', 'before' => $before, 'via' => 'easyadmin'], null, $register, 'cash_register', $register->getId());

        return $register;
    }

    // ---------------------------------------------------------------- représentation

    public function toArray(PosTerminalPayment $p): array
    {
        return [
            'paymentId' => $p->getId(),
            'stripePaymentIntentId' => str_starts_with($p->getStripePaymentIntentId(), 'pending:') ? null : $p->getStripePaymentIntentId(),
            'status' => $this->publicStatus($p),
            'captured' => $p->getStatus() === PosTerminalPayment::CAPTURED,
            'amount' => $p->getAmount(), 'currency' => $p->getCurrency(),
            'paymentMethodType' => $p->getPaymentMethodType(), 'cardBrand' => $p->getCardBrand(), 'last4' => $p->getLast4(),
            'failureCode' => $p->getFailureCode(), 'failureMessage' => $p->getFailureMessage(),
            'cashRegisterId' => $p->getCashRegister()->getId(), 'orderId' => $p->getOrderId(),
            'refund' => $this->refundArray($p),
            'createdAt' => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $p->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public function publicStatus(PosTerminalPayment $p): string
    {
        return match ($p->getStatus()) {
            PosTerminalPayment::CREATED, PosTerminalPayment::WAITING_FOR_CARD => 'waiting_for_card',
            PosTerminalPayment::PROCESSING => 'processing',
            PosTerminalPayment::AUTHORIZED, PosTerminalPayment::CAPTURED => 'succeeded',
            default => $p->getStatus(),
        };
    }

    // ---------------------------------------------------------------- interne

    private function refundArray(PosTerminalPayment $p): ?array
    {
        return $p->getRefundStatus() === null ? null : ['id' => $p->getRefundId(), 'status' => $p->getRefundStatus(), 'amount' => $p->getRefundAmount(), 'failureMessage' => $p->getRefundFailureMessage()];
    }

    private function refundOnline(PosTerminalPayment $payment, int $amount, ?User $user): void
    {
        try {
            $refund = $this->gateway->refundOnline($payment->getStripePaymentIntentId(), $amount, 'pos-refund-' . $payment->getStripePaymentIntentId() . '-' . $amount);
        } catch (PosTerminalGatewayException $e) {
            $payment->setRefundStatus(PosTerminalPayment::REFUND_FAILED)->setRefundAmount($amount)->setRefundFailureMessage($e->getMessage());
            $this->emProvider->getEntityManager()->flush();
            throw new CashRegisterException('TERMINAL_REFUND_FAILED', 'Remboursement Stripe refusé : ' . $e->getMessage(), $e->status >= 500 ? 502 : 409, [], ['terminalPaymentId' => $payment->getId(), 'stripeCode' => $e->stripeCode]);
        }
        $payment->setRefundId($refund['id'])->setRefundAmount($amount)->setRefundFailureMessage($refund['failureReason'])
            ->setRefundStatus(in_array($refund['status'], ['succeeded', 'pending'], true) ? PosTerminalPayment::REFUND_SUCCEEDED : PosTerminalPayment::REFUND_FAILED);
        $this->emProvider->getEntityManager()->flush();
        $this->record(PosAuditService::CARD_REFUND, $payment, $user, ['refundAmount' => $amount, 'refundId' => $refund['id'], 'onReader' => false]);
    }

    private function applyCard(PosTerminalPayment $payment, array $intent): PosTerminalPayment
    {
        return $payment->setPaymentMethodType($intent['paymentMethodType'] ?? $payment->getPaymentMethodType())
            ->setCardBrand($intent['cardBrand'] ?? $payment->getCardBrand())->setLast4($intent['last4'] ?? $payment->getLast4())
            ->setApplicationFee($intent['applicationFee'] ?? $payment->getApplicationFee());
    }

    private function attachReader(CashRegister $register, array $reader): void
    {
        $register->setStripeReaderId($reader['id'])->setStripeReaderLabel($reader['label'] ?: $reader['deviceType'])->setStripeReaderSimulated((bool) $reader['simulated'])->setTerminalEnabled(true);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::TERMINAL_CONFIGURED, ['action' => 'pair', 'readerId' => $reader['id'], 'label' => $reader['label'], 'simulated' => $reader['simulated'], 'via' => 'easyadmin'], null, $register, 'cash_register', $register->getId());
    }

    private function cancelIntentQuietly(PosTerminalPayment $payment): void
    {
        if (str_starts_with($payment->getStripePaymentIntentId(), 'pending:')) {
            return;
        }
        try {
            $this->gateway->cancelPaymentIntent($payment->getStripePaymentIntentId(), 'pos-cancel-' . $payment->getStripePaymentIntentId());
        } catch (PosTerminalGatewayException $e) {
            $this->logger->info(sprintf('[PosTerminal] annulation de %s impossible : %s', $payment->getStripePaymentIntentId(), $e->getMessage()));
        }
    }

    private function recordTransition(string $before, PosTerminalPayment $payment, ?User $user): void
    {
        $after = $payment->getStatus();
        if ($before === $after) {
            return;
        }
        $type = match ($after) {
            PosTerminalPayment::AUTHORIZED, PosTerminalPayment::CAPTURED => $before === PosTerminalPayment::AUTHORIZED ? null : PosAuditService::CARD_PAYMENT_SUCCEEDED,
            PosTerminalPayment::FAILED => PosAuditService::CARD_PAYMENT_FAILED,
            PosTerminalPayment::CANCELED => PosAuditService::CARD_PAYMENT_CANCELED,
            default => null,
        };
        if ($type !== null) {
            $this->record($type, $payment, $user);
        }
    }

    private function record(string $type, PosTerminalPayment $payment, ?User $user, array $extra = []): void
    {
        $session = $payment->getCaisseId() ? $this->emProvider->getEntityManager()->find(Caisse::class, $payment->getCaisseId()) : null;
        $this->audit->record($type, $extra + [
            'paymentId' => $payment->getId(), 'stripePaymentIntentId' => $payment->getStripePaymentIntentId(), 'amount' => $payment->getAmount(), 'currency' => $payment->getCurrency(),
            'readerId' => $payment->getReaderId(), 'status' => $payment->getStatus(), 'paymentMethodType' => $payment->getPaymentMethodType(),
            'cardBrand' => $payment->getCardBrand(), 'last4' => $payment->getLast4(), 'failureCode' => $payment->getFailureCode(), 'applicationFee' => $payment->getApplicationFee(),
        ], $session, $payment->getCashRegister(), 'terminal_payment', $payment->getId(), $user, $user === null);
    }

    /** @template T @param callable(): T $call @return T */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (PosTerminalGatewayException $e) {
            throw $this->translate($e);
        }
    }

    private function translate(PosTerminalGatewayException $e, array $context = []): CashRegisterException
    {
        return new CashRegisterException($e->errorCode, $e->getMessage(), $e->status, [], $context + array_filter(['stripeCode' => $e->stripeCode]));
    }

    private function repo(): \App\Repository\PosTerminalPaymentRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(PosTerminalPayment::class);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
