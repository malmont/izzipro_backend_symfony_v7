<?php

namespace App\Services\PosGiftCardService;

use App\Dto\PaymentMethodDTO;
use App\Entity\Caisse;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\Payments;
use App\Entity\PosGiftCard;
use App\Entity\PosGiftCardTransaction;
use App\Entity\PosReceipt;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\CaisseService\CashRoundingService;
use App\Services\OrderService\OrderCreationService;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosAuditService\PosReceiptService;
use App\Services\PosAuditService\PosSettingsService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\PosAuditService\PosTerminalService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use Doctrine\DBAL\LockMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Cartes-cadeaux et avoirs (lot F9, 27/10/2026). Chemins de caisse seulement.
 *  - Vente d'une carte : commande de type 3 « CarteCadeau » (passif, hors chiffre d'affaires), ligne libre non taxable,
 *    paiements normaux (espèces, carte déclarée, terminal C4), transaction de caisse 9, ticket « gift_card ».
 *  - Utilisation : paymentMethods[] {type 4 | 5, amount, code} ; débit dans la transaction de la vente, part portée par
 *    order.gift_card_applied, aucune ligne dans payments (taxes perçues à l'utilisation, comme toute vente).
 *  - Avoir : retour remboursé « store_credit » → carte de genre store_credit.
 *  - Codes GC-XXXX-XXXX-XXXX / AV-XXXX-XXXX-XXXX (60 bits), empreinte SHA-256 seule en base, 4 derniers caractères gardés.
 *  - Consultation limitée (anti-balayage) et journalisée ; annulation, ajustement, remplacement validés par un responsable (C3).
 */
class PosGiftCardService
{
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    public const PREFIX = [PosGiftCard::GIFT_CARD => 'GC', PosGiftCard::STORE_CREDIT => 'AV'];
    public const BARCODE_PREFIX = 'IZ-G-';
    private const SOURCE_POS = 2;
    private const STATUS_COMPLETED = 3;
    private const STATUS_CANCELED = 7;
    private const TYPE_GIFT_CARD = 3;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosAuditService $audit,
        private readonly PosApprovalService $approvals,
        private readonly PosTerminalService $terminals,
        private readonly PosSettingsService $posSettings,
        private readonly CashRegisterResolver $registers,
        private readonly PosReceiptService $receipts,
        private readonly OrderCreationService $orders,
        private readonly PaymentHandlerUseCase $payments,
        private readonly HandleCaisseTransactionUseCase $caisseTransactions,
        private readonly PosTerminalPaymentService $terminalPayments,
        private readonly CashRoundingService $rounding,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.pos_gift_card_lookup_terminal')] private readonly RateLimiterFactory $lookupByTerminal,
        #[Autowire(service: 'limiter.pos_gift_card_lookup_ip')] private readonly RateLimiterFactory $lookupByIp,
    ) {
    }

    // ------------------------------------------------------------------ réglages et codes

    public function settings(): array
    {
        return self::settingsOf($this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]));
    }

    /** Réglages d'un site (statique : utilisable par PosSettingsService sans dépendance circulaire) */
    public static function settingsOf(?Entreprise $e): array
    {
        return [
            'enabled' => $e?->isPosGiftCardEnabled() ?? false,
            'suggestedAmounts' => $e ? $e->giftCardAmounts() : Entreprise::GIFT_CARD_AMOUNTS_DEFAULT,
            'expiryMonths' => $e?->getPosGiftCardExpiryMonths(),
            'storeCredits' => ['enabled' => $e?->isPosStoreCreditEnabled() ?? false, 'expiryMonths' => $e?->getPosStoreCreditExpiryMonths()],
            'cashRefundMaxBalance' => $e?->getPosGiftCardCashRefundMax() ?? 500,
            'cashRefundBeyond' => $e?->isPosGiftCardCashRefundBeyond() ?? false,
            'codeAttemptsPer10Min' => $e?->getPosGiftCardCodeAttempts() ?? 10, // S2 : codes refusés par utilisateur et par terminal
            'codeFormat' => 'GC-XXXX-XXXX-XXXX | AV-XXXX-XXXX-XXXX',
            'barcodePrefix' => self::BARCODE_PREFIX,
            'offline' => false, // solde inconnu sans réseau : jamais hors ligne
        ];
    }

    /** Code normalisé (majuscules, sans espaces, préfixe de code-barres retiré, tirets rétablis) ou null si le format est invalide */
    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $code = strtoupper(preg_replace('/[\s\-]+/', '', trim($raw)) ?? '');
        if (str_starts_with($code, 'IZG')) {
            $code = substr($code, 3);
        }
        if (!preg_match('/^(GC|AV)([' . self::ALPHABET . ']{12})$/', $code, $m)) {
            return null;
        }

        return $m[1] . '-' . implode('-', str_split($m[2], 4));
    }

    public static function generate(string $kind): string
    {
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= self::ALPHABET[random_int(0, 31)];
        }

        return self::PREFIX[$kind] . '-' . implode('-', str_split($chars, 4));
    }

    public static function hash(string $code): string
    {
        return hash('sha256', 'pos-gift-card:' . $code);
    }

    public static function kindOf(string $code): string
    {
        return str_starts_with($code, 'AV-') ? PosGiftCard::STORE_CREDIT : PosGiftCard::GIFT_CARD;
    }

    /** @param ?string $code code en clair, affiché une seule fois (émission, remplacement) */
    public function toArray(PosGiftCard $card, ?string $code = null): array
    {
        return [
            'id' => $card->getId(), 'kind' => $card->getKind(), 'last4' => $card->getLast4(),
            'code' => $code, 'barcode' => $code !== null ? self::BARCODE_PREFIX . $code : null,
            'balance' => $card->getBalance(), 'initialAmount' => $card->getInitialAmount(), 'status' => $card->isExpired() && $card->getStatus() === PosGiftCard::ACTIVE ? 'expired' : $card->getStatus(),
            'usable' => $card->isUsable(), 'expiresAt' => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'issuedAt' => $card->getCreatedAt()->format(\DateTimeInterface::ATOM), 'issuedOrderId' => $card->getIssuedOrderId(), 'issuedReturnId' => $card->getIssuedReturnId(),
            'replacesId' => $card->getReplacesId(), 'replacedById' => $card->getReplacedById(),
        ];
    }

    // ------------------------------------------------------------------ recherche et consultation

    /** Carte par son code (404 GIFT_CARD_NOT_FOUND, 422 GIFT_CARD_CODE_INVALID) ; verrouillée pour écriture si demandé (transaction en cours) */
    public function find(mixed $raw, bool $lock = false): PosGiftCard
    {
        $code = self::normalize($raw);
        if ($code === null) {
            throw new CashRegisterException('GIFT_CARD_CODE_INVALID', 'Code de carte-cadeau ou d\'avoir invalide (format GC-XXXX-XXXX-XXXX ou AV-XXXX-XXXX-XXXX)', 422, [['path' => 'code', 'message' => 'format invalide']]);
        }
        $em = $this->emProvider->getEntityManager();
        $card = $em->getRepository(PosGiftCard::class)->findByHash(self::hash($code));
        if ($card === null || in_array($card->getStatus(), [PosGiftCard::CANCELED, PosGiftCard::REPLACED], true)) {
            // Réponse uniforme (S2) : un code inconnu, annulé ou remplacé est refusé sans révéler s'il existe ; l'essai compte pour la limite
            $this->countFailedCode($code);
            throw new CashRegisterException('GIFT_CARD_NOT_FOUND', 'Carte-cadeau ou avoir inconnu ou inutilisable', 404, [], ['last4' => substr($code, -4)]);
        }
        if ($lock) {
            $em->lock($card, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($card);
        }

        return $card;
    }

    /**
     * Codes refusés (S2, 30/10/2026) : au-delà de N essais par 10 minutes par utilisateur OU par terminal (réglage du site, 0 = sans limite),
     * consultation et paiement sont refusés 429 GIFT_CARD_LOOKUP_LIMIT {retryAfterSeconds}. Comptés sur le journal (GIFT_CARD_LOOKUP, result
     * GIFT_CARD_NOT_FOUND), journalisés. Appelé avant toute recherche de code.
     */
    public function assertCodeAttempts(User $user): void
    {
        $limit = $this->settings()['codeAttemptsPer10Min'];
        if ($limit <= 0) {
            return;
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');
        $terminal = $this->terminals->current();
        $byUser = (int) $conn->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = ? AND user_id = ? AND occurred_at > ? AND payload LIKE '%\"result\":\"GIFT_CARD_NOT_FOUND\"%'", [PosAuditService::GIFT_CARD_LOOKUP, $user->getId(), $since]);
        $byTerminal = $terminal ? (int) $conn->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = ? AND terminal_id = ? AND occurred_at > ? AND payload LIKE '%\"result\":\"GIFT_CARD_NOT_FOUND\"%'", [PosAuditService::GIFT_CARD_LOOKUP, $terminal['id'], $since]) : 0;
        if ($byUser >= $limit || $byTerminal >= $limit) {
            $this->audit->record(PosAuditService::GIFT_CARD_LOOKUP, ['result' => 'code_attempts_limited', 'limit' => $limit, 'byUser' => $byUser, 'byTerminal' => $byTerminal], subjectType: 'gift_card', user: $user);
            throw new CashRegisterException('GIFT_CARD_LOOKUP_LIMIT', 'Trop de codes refusés : réessayez dans quelques minutes', 429, [], ['retryAfterSeconds' => 600, 'limit' => $limit]);
        }
    }

    /** Journalise un code refusé (inconnu, annulé ou remplacé) pour la limite d'essais ; l'utilisateur courant est celui de la requête */
    private function countFailedCode(string $code): void
    {
        $this->audit->record(PosAuditService::GIFT_CARD_LOOKUP, ['result' => 'GIFT_CARD_NOT_FOUND', 'last4' => substr($code, -4)], subjectType: 'gift_card');
    }

    /** Consultation du solde : limitée par terminal et par adresse IP (429 GIFT_CARD_LOOKUP_LIMIT), journalisée (succès et échecs) */
    public function lookup(mixed $raw, User $user): array
    {
        $this->assertCodeAttempts($user);
        $terminal = $this->terminals->current();
        $request = $this->requestStack->getMainRequest();
        $limits = [$this->lookupByTerminal->create('t' . ($terminal['id'] ?? 'none')), $this->lookupByIp->create((string) ($request?->getClientIp() ?? 'none'))];
        foreach ($limits as $limiter) {
            $limit = $limiter->consume(1);
            if (!$limit->isAccepted()) {
                $this->audit->record(PosAuditService::GIFT_CARD_LOOKUP, ['result' => 'rate_limited', 'last4' => is_string($raw) ? substr(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '', -4) : null], subjectType: 'gift_card', user: $user);
                throw new CashRegisterException('GIFT_CARD_LOOKUP_LIMIT', 'Trop de consultations : réessayez plus tard', 429, [], ['retryAfterSeconds' => max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
            }
        }
        try {
            $card = $this->find($raw);
        } catch (CashRegisterException $e) {
            if ($e->errorCode !== 'GIFT_CARD_NOT_FOUND') { // un code inconnu est déjà compté par find()
                $this->audit->record(PosAuditService::GIFT_CARD_LOOKUP, ['result' => $e->errorCode, 'last4' => is_string($raw) ? substr(preg_replace('/[^A-Za-z0-9]/', '', strtoupper($raw)) ?? '', -4) : null], subjectType: 'gift_card', user: $user);
            }
            throw $e;
        }
        $this->audit->record(PosAuditService::GIFT_CARD_LOOKUP, ['result' => 'ok', 'kind' => $card->getKind(), 'last4' => $card->getLast4(), 'balance' => $card->getBalance(), 'status' => $card->getStatus()], subjectType: 'gift_card', subjectId: $card->getId(), user: $user);

        return $this->toArray($card);
    }

    // ------------------------------------------------------------------ vente d'une carte

    /**
     * Vente d'une carte-cadeau (dans une transaction) : {amount, code?, paymentMethods [{type 1|2|3, amount (dollars), terminalPaymentId?}], cashRegisterId?}
     *
     * @return array{card: PosGiftCard, code: string, order: Order, receipt: PosReceipt, session: Caisse}
     */
    public function issue(User $user, array $body, ?string $posKey): array
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            throw new CashRegisterException('GIFT_CARD_DISABLED', 'Les cartes-cadeaux sont désactivées sur ce site', 409);
        }
        $amount = $body['amount'] ?? null;
        if (!is_numeric($amount) || (int) $amount <= 0 || (int) $amount != $amount) {
            throw new CashRegisterException('VALIDATION_FAILED', 'amount : montant en cents (entier positif) attendu', 422, [['path' => 'amount', 'message' => 'entier positif (cents)']]);
        }
        $amount = (int) $amount;
        $methods = $this->paymentMethods($body['paymentMethods'] ?? null);
        $paid = (int) round(array_sum(array_map(fn (PaymentMethodDTO $m) => $m->getAmount(), $methods)) * 100);
        if ($paid !== $amount) {
            throw new CashRegisterException('PAYMENT_TOTAL_MISMATCH', 'Les paiements doivent couvrir exactement le montant de la carte', 400, [], ['amount' => $amount, 'paid' => $paid]);
        }
        $em = $this->emProvider->getEntityManager();
        $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, 409);
        $register = $caisse->getCashRegister();
        $this->receipts->lockRegister($register);
        $this->posSettings->checkPaymentMethods($methods, $register);
        // Code : fourni (carte physique pré-imprimée) ou généré ; empreinte unique
        if (isset($body['code'])) {
            $code = self::normalize($body['code']);
            if ($code === null || self::kindOf($code) !== PosGiftCard::GIFT_CARD) {
                throw new CashRegisterException('GIFT_CARD_CODE_INVALID', 'Code de carte invalide : format GC-XXXX-XXXX-XXXX attendu (alphabet sans 0, 1, I, O)', 422, [['path' => 'code', 'message' => 'format invalide']]);
            }
            if ($em->getRepository(PosGiftCard::class)->findByHash(self::hash($code)) !== null) {
                throw new CashRegisterException('GIFT_CARD_CODE_TAKEN', 'Ce code de carte est déjà utilisé', 409, [], ['last4' => substr($code, -4)]);
            }
        } else {
            do {
                $code = self::generate(PosGiftCard::GIFT_CARD);
            } while ($em->getRepository(PosGiftCard::class)->findByHash(self::hash($code)) !== null);
        }
        // Paiement par terminal (C4) : vérifié avant toute écriture, capturé après la commande
        $terminalParts = [];
        foreach ($methods as $m) {
            if ($m->getTerminalPaymentId() !== null) {
                $terminalParts[] = [$m, $this->terminalPayments->verifyForSale($m->getTerminalPaymentId(), (int) round($m->getAmount() * 100), $register)];
            }
        }

        // Commande de type 3 : une ligne libre non taxable, aucun stock, aucune taxe
        $order = $this->orders->createOrder($user, $em->getReference(OrderSource::class, self::SOURCE_POS), null, null, $em->getReference(StatusCommande::class, self::STATUS_COMPLETED), $em->getReference(OrderType::class, self::TYPE_GIFT_CARD));
        $item = (new OrderItems())->setOrderAssociated($order)->setQuantity(1)->setUnitPrice((float) $amount)->setTotalPrice((float) $amount)->setCustomLabel('Carte-cadeau •••• ' . substr($code, -4))->setTaxable(false);
        $order->addOrderItem($item);
        $order->setSubTotal($amount)->setTotalTax(0)->setTotalAmount($amount);
        if (is_numeric($body['customerId'] ?? null)) { // fiche client (F8) : acheteur de la carte (aucun point : passif)
            $order->setCustomerId((int) $em->getConnection()->fetchOne('SELECT id FROM pos_customer WHERE id = ? AND erased_at IS NULL AND merged_into_id IS NULL', [(int) $body['customerId']]) ?: throw new CashRegisterException('CUSTOMER_NOT_FOUND', 'Fiche client introuvable', 404, [], ['customerId' => (int) $body['customerId']]));
        }
        $em->persist($order);
        $em->persist($item);
        $em->flush();
        $cash = 0;
        foreach ($methods as $m) {
            $cents = (int) round($m->getAmount() * 100);
            if ($cents <= 0) {
                continue;
            }
            if ($m->getType() === 2) {
                $cash += $cents;
            }
            $this->payments->handlePayment($order, $cents, $m->getType(), 2, 2, new \DateTime(), null);
        }
        $cardPayments = $em->getRepository(Payments::class)->findBy(['orderPayment' => $order, 'paymentMethod' => 1], ['id' => 'ASC']);
        foreach ($terminalParts as [$m, $terminal]) {
            $this->terminalPayments->captureForSale($terminal, $order);
            if ($cardPayment = array_shift($cardPayments)) {
                $cardPayment->setStripePaymentId($terminal->getStripePaymentIntentId())->setStripeStatus('succeeded')->setStripeCardBrand($terminal->getCardBrand())->setStripeLast4($terminal->getLast4());
            }
        }
        $this->caisseTransactions->execute($caisse, $order, $user, $amount, HandleCaisseTransactionUseCase::GIFT_CARD_SALE, [], $cash, null);
        $receipt = $this->receipts->issue($order, $caisse, PosReceipt::GIFT_CARD, $amount);

        $card = new PosGiftCard(PosGiftCard::GIFT_CARD, self::hash($code), substr($code, -4), $amount);
        $card->setCustomerId($order->getCustomerId()); // fiche client (F8)
        $card->setIssuedOrderId($order->getId())->setIssuedById($user->getId())->setCashRegisterId($register->getId())
            ->setExpiresAt($settings['expiryMonths'] !== null ? new \DateTimeImmutable('+' . $settings['expiryMonths'] . ' months') : null);
        $em->persist($card);
        $em->flush();
        $this->transaction($card, PosGiftCardTransaction::ISSUE, $amount, $order->getId(), null, $caisse, $user);
        $this->audit->record(PosAuditService::GIFT_CARD_SOLD, [
            'giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $amount, 'expiresAt' => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM), 'providedCode' => isset($body['code']),
            'reference' => $order->getReference(), 'receiptNumber' => $receipt->getNumber(),
            'payments' => array_map(fn (PaymentMethodDTO $m) => ['type' => $m->getType(), 'amount' => (int) round($m->getAmount() * 100), 'terminalPaymentId' => $m->getTerminalPaymentId()], $methods),
        ], $caisse, null, 'gift_card', $card->getId(), $user);

        return ['card' => $card, 'code' => $code, 'order' => $order, 'receipt' => $receipt, 'session' => $caisse];
    }

    /** @return list<PaymentMethodDTO> espèces, carte déclarée, PayPal, terminal ; jamais une carte-cadeau pour acheter une carte */
    private function paymentMethods(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new CashRegisterException('PAYMENT_METHODS_REQUIRED', 'Au moins un moyen de paiement est requis', 400);
        }
        $methods = [];
        foreach (array_values($raw) as $i => $m) {
            if (!is_array($m) || !is_numeric($m['type'] ?? null) || !is_numeric($m['amount'] ?? null) || (float) $m['amount'] < 0) {
                throw new CashRegisterException('PAYMENT_METHODS_INVALID', 'Chaque moyen de paiement doit avoir un type et un montant positif', 400, [['path' => "paymentMethods[$i]", 'message' => '{type, amount}']]);
            }
            if (in_array((int) $m['type'], [PaymentMethodDTO::GIFT_CARD, PaymentMethodDTO::STORE_CREDIT], true)) {
                throw new CashRegisterException('GIFT_CARD_PAYMENT_INVALID', 'Une carte-cadeau ou un avoir ne peut pas payer une carte-cadeau', 409, [['path' => "paymentMethods[$i].type", 'message' => 'types 1, 2 ou 3']]);
            }
            $ref = $m['terminalPaymentId'] ?? $m['stripePaymentId'] ?? null;
            $methods[] = new PaymentMethodDTO((int) $m['type'], (float) $m['amount'], $ref !== null && is_scalar($ref) ? (string) $ref : null);
        }

        return $methods;
    }

    // ------------------------------------------------------------------ utilisation (vente, échange)

    /**
     * Débit d'une carte ou d'un avoir pour une part de vente (DANS la transaction de la vente, ligne verrouillée).
     * 409 GIFT_CARD_DISABLED, GIFT_CARD_KIND_MISMATCH, GIFT_CARD_INACTIVE, GIFT_CARD_EXPIRED, GIFT_CARD_INSUFFICIENT {balance}
     */
    public function redeem(PaymentMethodDTO $part, Order $order, User $user, Caisse $caisse): PosGiftCard
    {
        $settings = $this->settings();
        $cents = (int) round($part->getAmount() * 100);
        if ($cents <= 0) {
            throw new CashRegisterException('GIFT_CARD_PAYMENT_INVALID', 'Part carte-cadeau : montant positif attendu', 400);
        }
        $this->assertCodeAttempts($user); // codes devinables (S2) : même limite en paiement qu'en consultation
        $card = $this->find($part->getCode(), true);
        $this->assertKind($card, $part->getType());
        if (($card->getKind() === PosGiftCard::GIFT_CARD && !$settings['enabled']) || ($card->getKind() === PosGiftCard::STORE_CREDIT && !$settings['storeCredits']['enabled'])) {
            throw new CashRegisterException('GIFT_CARD_DISABLED', 'Les cartes-cadeaux ou les avoirs sont désactivés sur ce site', 409, [], ['kind' => $card->getKind()]);
        }
        $this->assertUsable($card);
        if ($card->getBalance() < $cents) {
            throw new CashRegisterException('GIFT_CARD_INSUFFICIENT', 'Solde insuffisant sur la carte-cadeau ou l\'avoir', 409, [], ['last4' => $card->getLast4(), 'balance' => $card->getBalance(), 'requested' => $cents]);
        }
        $card->setBalance($card->getBalance() - $cents);
        $this->transaction($card, PosGiftCardTransaction::REDEEM, -$cents, $order->getId(), null, $caisse, $user);
        $order->setGiftCardApplied($order->getGiftCardApplied() + $cents);
        $this->audit->record(PosAuditService::GIFT_CARD_REDEEMED, ['giftCardId' => $card->getId(), 'kind' => $card->getKind(), 'last4' => $card->getLast4(), 'amount' => $cents, 'balanceAfter' => $card->getBalance(), 'reference' => $order->getReference()],
            $caisse, null, 'order', $order->getId(), $user);

        return $card;
    }

    /**
     * Devis : solde des cartes présentées et part applicable au montant dû, sans débit.
     *
     * @param list<array{code: string, amount?: int}> $raw
     * @return array{giftCards: list<array>, giftCardApplied: int, amountDue: int, cashAmountDue: int}
     */
    public function preview(mixed $raw, int $amountDue): array
    {
        $out = [];
        $applied = 0;
        $seen = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $i => $g) {
            $card = $this->find(is_array($g) ? ($g['code'] ?? null) : $g);
            if (isset($seen[$card->getId()])) {
                throw new CashRegisterException('GIFT_CARD_DUPLICATE', 'La même carte est présentée deux fois', 422, [['path' => "giftCards[$i]", 'message' => 'carte en double']]);
            }
            $seen[$card->getId()] = true;
            $usable = $card->isUsable();
            $wanted = is_array($g) && is_numeric($g['amount'] ?? null) ? (int) $g['amount'] : null;
            $part = $usable ? min($card->getBalance(), max(0, $amountDue - $applied), $wanted ?? PHP_INT_MAX) : 0;
            $applied += $part;
            $out[] = $this->toArray($card) + ['applied' => $part, 'reason' => $usable ? null : ($card->isExpired() ? 'GIFT_CARD_EXPIRED' : 'GIFT_CARD_INACTIVE')];
        }
        $due = max(0, $amountDue - $applied);

        return ['giftCards' => $out, 'giftCardApplied' => $applied, 'amountDue' => $due, 'cashAmountDue' => $this->rounding->round($due)];
    }

    // ------------------------------------------------------------------ retours

    /**
     * Recrédit des cartes d'une vente retournée (dans la transaction du retour) : [giftCardId => disponible] → montant recrédité,
     * jusqu'à $toRefund ; chaque recrédit réactive la carte.
     *
     * @return array{amount: int, cards: list<array{giftCardId: int, last4: string, amount: int}>}
     */
    public function refundToCards(array $available, int $toRefund, Order $returnOrder, int $originalOrderId, Caisse $caisse, User $user): array
    {
        $em = $this->emProvider->getEntityManager();
        $done = 0;
        $cards = [];
        foreach ($available as $id => $max) {
            if ($done >= $toRefund) {
                break;
            }
            $card = $em->find(PosGiftCard::class, (int) $id, LockMode::PESSIMISTIC_WRITE);
            if ($card === null || in_array($card->getStatus(), [PosGiftCard::CANCELED, PosGiftCard::REPLACED], true)) {
                continue;
            }
            $part = min($max, $toRefund - $done);
            $card->setBalance($card->getBalance() + $part);
            // le recrédit est rattaché à la vente d'origine (pour le calcul du disponible) et au retour
            $tx = $this->transaction($card, PosGiftCardTransaction::REFUND, $part, $originalOrderId, $returnOrder->getId(), $caisse, $user);
            $done += $part;
            $cards[] = ['giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $part, 'balance' => $card->getBalance()];
            $this->audit->record(PosAuditService::GIFT_CARD_REFUNDED, ['giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $part, 'balanceAfter' => $card->getBalance(), 'returnOrderId' => $returnOrder->getId(), 'originalOrderId' => $originalOrderId], $caisse, null, 'order', $returnOrder->getId(), $user);
        }

        return ['amount' => $done, 'cards' => $cards];
    }

    /**
     * Avoir émis par un retour (dans sa transaction) : code AV-…, expiration selon le réglage.
     *
     * @return array{card: PosGiftCard, code: string}
     */
    public function issueStoreCredit(int $amount, Order $returnOrder, Caisse $caisse, User $user): array
    {
        $settings = $this->settings();
        if (!$settings['storeCredits']['enabled']) {
            throw new CashRegisterException('STORE_CREDIT_DISABLED', 'Les avoirs sont désactivés sur ce site', 409);
        }
        if ($amount <= 0) {
            throw new CashRegisterException('RETURN_EMPTY', 'Aucun montant à porter sur un avoir', 422);
        }
        $em = $this->emProvider->getEntityManager();
        do {
            $code = self::generate(PosGiftCard::STORE_CREDIT);
        } while ($em->getRepository(PosGiftCard::class)->findByHash(self::hash($code)) !== null);
        $card = new PosGiftCard(PosGiftCard::STORE_CREDIT, self::hash($code), substr($code, -4), $amount);
        $card->setIssuedById($user->getId())->setCashRegisterId($caisse->getCashRegister()->getId())
            ->setExpiresAt($settings['storeCredits']['expiryMonths'] !== null ? new \DateTimeImmutable('+' . $settings['storeCredits']['expiryMonths'] . ' months') : null);
        $em->persist($card);
        $em->flush();
        $this->transaction($card, PosGiftCardTransaction::ISSUE, $amount, null, $returnOrder->getId(), $caisse, $user);
        $this->issuedCodes[$card->getId()] = $code;
        $this->audit->record(PosAuditService::STORE_CREDIT_ISSUED, ['giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $amount, 'expiresAt' => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM), 'returnOrderId' => $returnOrder->getId()],
            $caisse, null, 'order', $returnOrder->getId(), $user);

        return ['card' => $card, 'code' => $code];
    }

    /** @var array<int, string> codes en clair émis pendant la requête (avoirs), par identifiant de carte */
    private array $issuedCodes = [];

    public function issuedCode(?int $cardId): ?string
    {
        return $cardId !== null ? ($this->issuedCodes[$cardId] ?? null) : null;
    }

    public function attachReturn(PosGiftCard $card, int $returnId): void
    {
        $card->setIssuedReturnId($returnId);
        $em = $this->emProvider->getEntityManager();
        foreach ($em->getRepository(PosGiftCardTransaction::class)->findBy(['giftCard' => $card, 'kind' => PosGiftCardTransaction::ISSUE]) as $tx) {
            $tx->setReturnId($returnId);
        }
        $em->flush();
    }

    // ------------------------------------------------------------------ annulation, ajustement, remboursement de solde, remplacement

    /**
     * Annulation d'une carte vendue jamais utilisée (dans une transaction) : remboursement par le moyen d'origine, validation C3
     * gift_card_cancel {amount}. {terminalRefundId?, cashRegisterId?}
     */
    public function cancel(User $user, mixed $raw, array $body, ?string $posKey): array
    {
        $em = $this->emProvider->getEntityManager();
        $card = $this->find($raw, true);
        if ($card->getKind() !== PosGiftCard::GIFT_CARD || $card->getIssuedOrderId() === null) {
            throw new CashRegisterException('GIFT_CARD_NOT_CANCELABLE', 'Seule une carte-cadeau vendue peut être annulée (un avoir se rembourse par son solde)', 409, [], ['kind' => $card->getKind()]);
        }
        if ($card->getStatus() !== PosGiftCard::ACTIVE) {
            throw new CashRegisterException('GIFT_CARD_INACTIVE', 'Carte déjà annulée, remplacée ou épuisée', 409, [], ['status' => $card->getStatus()]);
        }
        if ($card->getBalance() !== $card->getInitialAmount() || $em->getRepository(PosGiftCardTransaction::class)->count(['giftCard' => $card, 'kind' => PosGiftCardTransaction::REDEEM]) > 0) {
            throw new CashRegisterException('GIFT_CARD_USED', 'Cette carte a déjà servi : annulation impossible (remboursement du solde en espèces selon le réglage)', 409, [], ['balance' => $card->getBalance(), 'initialAmount' => $card->getInitialAmount()]);
        }
        $order = $em->find(Order::class, $card->getIssuedOrderId()) ?? throw new CashRegisterException('GIFT_CARD_NOT_CANCELABLE', 'Vente d\'émission introuvable', 409);
        $amount = $card->getBalance();
        $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, 409);
        $this->receipts->lockRegister($caisse->getCashRegister());
        $this->approvals->requireFor(PosApprovalService::GIFT_CARD_CANCEL, $user, ['amount' => $amount], $amount);

        // Remboursement par le moyen d'origine : terminal (en ligne ou déjà présenté sur le lecteur), carte déclarée, espèces
        $refunds = [];
        $cash = 0;
        foreach ($order->getPayments() as $payment) {
            $cents = (int) round((float) $payment->getAmount());
            if ($cents <= 0) {
                continue;
            }
            $method = $payment->getPaymentMethod()?->getId();
            if ($method === 1 && $payment->getStripePaymentId() !== null) {
                $terminal = $this->terminalPayments->findByOrder((int) $order->getId());
                if ($terminal !== null) {
                    $this->terminalPayments->refundForCancel($terminal, is_string($body['terminalRefundId'] ?? null) ? $body['terminalRefundId'] : null, $user);
                }
            }
            if ($method === 2) {
                $cash += $cents;
            }
            $this->payments->handlePayment($order, -$cents, $method, 1, 2, new \DateTime(), null);
            $refunds[] = ['type' => $method, 'amount' => $cents];
        }
        $this->caisseTransactions->execute($caisse, $order, $user, $amount, HandleCaisseTransactionUseCase::GIFT_CARD_REFUND, [], $cash, null);
        $creditNote = $this->receipts->issue($order, $caisse, PosReceipt::CREDIT_NOTE, -$amount);
        $order->setStatus($em->getReference(StatusCommande::class, self::STATUS_CANCELED));
        $order->setStatusUpdatedAt(new \DateTime());
        $card->setStatus(PosGiftCard::CANCELED)->setCanceledAt(new \DateTimeImmutable());
        $card->setBalance(0);
        $card->setStatus(PosGiftCard::CANCELED);
        $this->transaction($card, PosGiftCardTransaction::CANCEL, -$amount, $order->getId(), null, $caisse, $user)->setApprovedBy($this->approvals->approvedBy());
        $em->flush();
        $this->audit->record(PosAuditService::GIFT_CARD_CANCELED, ['giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $amount, 'refunds' => $refunds, 'reference' => $order->getReference(), 'creditNoteNumber' => $creditNote->getNumber()],
            $caisse, null, 'gift_card', $card->getId(), $user);

        return ['card' => $card, 'order' => $order, 'creditNote' => $creditNote, 'refunds' => $refunds, 'cash' => $cash];
    }

    /** Ajustement manuel du solde (dans une transaction) : {amount signé, reason} ; validation C3 gift_card_adjust {amount absolu} */
    public function adjust(User $user, mixed $raw, array $body): PosGiftCard
    {
        $card = $this->find($raw, true);
        $amount = $body['amount'] ?? null;
        if (!is_numeric($amount) || (int) $amount === 0 || (int) $amount != $amount) {
            throw new CashRegisterException('VALIDATION_FAILED', 'amount : entier signé non nul (cents) attendu', 422, [['path' => 'amount', 'message' => 'entier signé non nul']]);
        }
        $amount = (int) $amount;
        $reason = isset($body['reason']) && is_string($body['reason']) && trim($body['reason']) !== '' ? trim($body['reason']) : null;
        if ($reason === null) {
            throw new CashRegisterException('VALIDATION_FAILED', 'reason : motif requis pour un ajustement de solde', 422, [['path' => 'reason', 'message' => 'requis']]);
        }
        if (in_array($card->getStatus(), [PosGiftCard::CANCELED, PosGiftCard::REPLACED], true)) {
            throw new CashRegisterException('GIFT_CARD_INACTIVE', 'Carte annulée ou remplacée', 409, [], ['status' => $card->getStatus()]);
        }
        if ($card->getBalance() + $amount < 0) {
            throw new CashRegisterException('GIFT_CARD_INSUFFICIENT', 'Le solde ne peut pas devenir négatif', 409, [], ['balance' => $card->getBalance(), 'amount' => $amount]);
        }
        $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, 409);
        $this->approvals->requireFor(PosApprovalService::GIFT_CARD_ADJUST, $user, ['amount' => abs($amount)], abs($amount));
        $card->setBalance($card->getBalance() + $amount);
        $this->transaction($card, PosGiftCardTransaction::ADJUST, $amount, null, null, $caisse, $user)->setReason($reason)->setApprovedBy($this->approvals->approvedBy());
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::GIFT_CARD_ADJUSTED, ['giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $amount, 'balanceAfter' => $card->getBalance(), 'reason' => $reason], $caisse, null, 'gift_card', $card->getId(), $user);

        return $card;
    }

    /**
     * Remboursement du solde en espèces (dans une transaction) : de droit jusqu'au seuil du site (Québec : 5,00 $), au-delà seulement
     * si le réglage l'autorise, avec validation C3 gift_card_adjust {amount}. Espèces arrondies au pas du site.
     */
    public function cashOut(User $user, mixed $raw, array $body): array
    {
        $settings = $this->settings();
        $card = $this->find($raw, true);
        $this->assertUsable($card);
        $balance = $card->getBalance();
        if ($balance > $settings['cashRefundMaxBalance']) {
            if (!$settings['cashRefundBeyond']) {
                throw new CashRegisterException('GIFT_CARD_CASH_OUT_FORBIDDEN', 'Remboursement en espèces refusé au-delà du seuil du site', 409, [], ['balance' => $balance, 'maxBalance' => $settings['cashRefundMaxBalance']]);
            }
            $this->approvals->requireFor(PosApprovalService::GIFT_CARD_ADJUST, $user, ['amount' => $balance], $balance);
        }
        $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, 409);
        $this->receipts->lockRegister($caisse->getCashRegister());
        $cash = $this->rounding->round($balance);
        $this->caisseTransactions->execute($caisse, null, $user, $balance, HandleCaisseTransactionUseCase::GIFT_CARD_REFUND, [], $cash, $cash - $balance !== 0 ? $cash - $balance : null);
        $card->setBalance(0);
        $this->transaction($card, PosGiftCardTransaction::CASH_OUT, -$balance, null, null, $caisse, $user)->setApprovedBy($this->approvals->approvedBy());
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::GIFT_CARD_CASH_OUT, ['giftCardId' => $card->getId(), 'kind' => $card->getKind(), 'last4' => $card->getLast4(), 'amount' => $balance, 'cash' => $cash, 'cashRounding' => $cash - $balance], $caisse, null, 'gift_card', $card->getId(), $user);

        return ['card' => $card, 'amount' => $balance, 'cash' => $cash, 'cashRounding' => $cash - $balance];
    }

    /**
     * Remplacement d'une carte perdue (dans une transaction) : la carte est retrouvée par sa vente d'émission (orderId ou code-barres
     * du ticket IZ-C-<poste>-<n°>) ou par son retour d'émission (returnId, avoir) et ses 4 derniers caractères ; validation C3
     * gift_card_replace {amount = solde}. L'ancienne carte est désactivée, le solde reporté sur une nouvelle (nouveau code).
     *
     * @return array{old: PosGiftCard, card: PosGiftCard, code: string}
     */
    public function replace(User $user, array $body): array
    {
        $em = $this->emProvider->getEntityManager();
        $last4 = isset($body['last4']) && is_string($body['last4']) ? strtoupper(trim($body['last4'])) : '';
        if (!preg_match('/^[' . self::ALPHABET . ']{4}$/', $last4)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'last4 : les 4 derniers caractères du code sont requis', 422, [['path' => 'last4', 'message' => '4 caractères']]);
        }
        $criteria = [];
        if (is_numeric($body['orderId'] ?? null)) {
            $criteria = ['issuedOrderId' => (int) $body['orderId']];
        } elseif (is_string($body['barcode'] ?? null) && preg_match('/^IZ-C-(\d+)-(\d+)$/i', trim($body['barcode']), $m)) {
            $receipt = $em->getRepository(PosReceipt::class)->findOneBy(['cashRegister' => (int) $m[1], 'kind' => PosReceipt::GIFT_CARD, 'number' => (int) $m[2]]);
            $criteria = ['issuedOrderId' => $receipt?->getOrder()->getId() ?? -1];
        } elseif (is_numeric($body['returnId'] ?? null)) {
            $criteria = ['issuedReturnId' => (int) $body['returnId']];
        } else {
            throw new CashRegisterException('VALIDATION_FAILED', 'orderId, barcode (ticket de la carte) ou returnId requis', 422, [['path' => 'orderId', 'message' => 'orderId | barcode | returnId']]);
        }
        $old = $em->getRepository(PosGiftCard::class)->findOneBy($criteria + ['last4' => $last4]);
        if ($old === null) {
            throw new CashRegisterException('GIFT_CARD_NOT_FOUND', 'Aucune carte ne correspond à cette vente et à ces 4 caractères', 404);
        }
        $em->lock($old, LockMode::PESSIMISTIC_WRITE);
        $em->refresh($old);
        if (!in_array($old->getStatus(), [PosGiftCard::ACTIVE, PosGiftCard::DEPLETED], true)) {
            throw new CashRegisterException('GIFT_CARD_INACTIVE', 'Carte annulée ou déjà remplacée', 409, [], ['status' => $old->getStatus()]);
        }
        $balance = $old->getBalance();
        $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null, 409);
        $this->approvals->requireFor(PosApprovalService::GIFT_CARD_REPLACE, $user, ['amount' => $balance], $balance);
        do {
            $code = self::generate($old->getKind());
        } while ($em->getRepository(PosGiftCard::class)->findByHash(self::hash($code)) !== null);
        $card = new PosGiftCard($old->getKind(), self::hash($code), substr($code, -4), $balance);
        $card->setIssuedOrderId($old->getIssuedOrderId())->setIssuedReturnId($old->getIssuedReturnId())->setIssuedById($user->getId())->setCashRegisterId($caisse->getCashRegister()->getId())
            ->setExpiresAt($old->getExpiresAt())->setReplacesId($old->getId());
        $em->persist($card);
        $em->flush();
        $old->setBalance(0);
        $old->setStatus(PosGiftCard::REPLACED)->setReplacedById($card->getId());
        $this->transaction($old, PosGiftCardTransaction::REPLACE_OUT, -$balance, null, null, $caisse, $user)->setApprovedBy($this->approvals->approvedBy());
        $this->transaction($card, PosGiftCardTransaction::REPLACE_IN, $balance, null, null, $caisse, $user)->setApprovedBy($this->approvals->approvedBy());
        $em->flush();
        $this->audit->record(PosAuditService::GIFT_CARD_REPLACED, ['oldGiftCardId' => $old->getId(), 'oldLast4' => $old->getLast4(), 'giftCardId' => $card->getId(), 'last4' => $card->getLast4(), 'amount' => $balance, 'kind' => $card->getKind()],
            $caisse, null, 'gift_card', $card->getId(), $user);

        return ['old' => $old, 'card' => $card, 'code' => $code];
    }

    // ------------------------------------------------------------------ rapports

    /** Bloc giftCards des rapports X et Z : vendu, utilisé, avoirs émis, remboursé, ajusté (session) et solde en cours du site */
    public function reportBlock(Caisse $session): array
    {
        $em = $this->emProvider->getEntityManager();

        return $em->getRepository(PosGiftCardTransaction::class)->totalsForSession((int) $session->getId()) + ['outstanding' => $em->getRepository(PosGiftCard::class)->outstanding()];
    }

    /** Parts carte-cadeau d'une commande (ticket) : [{kind, last4, amount}] */
    public function redemptionsOf(Order $order): array
    {
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(PosGiftCardTransaction::class)->findBy(['orderId' => $order->getId(), 'kind' => PosGiftCardTransaction::REDEEM], ['id' => 'ASC']) as $tx) {
            $out[] = ['kind' => $tx->getGiftCard()->getKind(), 'last4' => $tx->getGiftCard()->getLast4(), 'amount' => -$tx->getAmount()];
        }

        return $out;
    }

    // ------------------------------------------------------------------ aides

    private function transaction(PosGiftCard $card, string $kind, int $amount, ?int $orderId, ?int $returnId, ?Caisse $caisse, ?User $user): PosGiftCardTransaction
    {
        $tx = (new PosGiftCardTransaction($card, $kind, $amount))->setOrderId($orderId)->setReturnId($returnId)->setCaisseId($caisse?->getId())->setUserId($user?->getId())->setTerminalId($this->terminals->current()['id'] ?? null);
        $em = $this->emProvider->getEntityManager();
        $em->persist($tx);
        $em->flush();

        return $tx;
    }

    private function assertKind(PosGiftCard $card, int $type): void
    {
        $expected = $type === PaymentMethodDTO::STORE_CREDIT ? PosGiftCard::STORE_CREDIT : PosGiftCard::GIFT_CARD;
        if ($card->getKind() !== $expected) {
            throw new CashRegisterException('GIFT_CARD_KIND_MISMATCH', $expected === PosGiftCard::GIFT_CARD ? 'Ce code est un avoir (type 5), pas une carte-cadeau' : 'Ce code est une carte-cadeau (type 4), pas un avoir', 409, [], ['kind' => $card->getKind()]);
        }
    }

    private function assertUsable(PosGiftCard $card): void
    {
        if ($card->isExpired()) {
            throw new CashRegisterException('GIFT_CARD_EXPIRED', 'Carte-cadeau ou avoir expiré', 409, [], ['last4' => $card->getLast4(), 'expiresAt' => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM)]);
        }
        if ($card->getStatus() !== PosGiftCard::ACTIVE || $card->getBalance() <= 0) {
            throw new CashRegisterException('GIFT_CARD_INACTIVE', 'Carte-cadeau ou avoir inutilisable (annulé, remplacé ou épuisé)', 409, [], ['last4' => $card->getLast4(), 'status' => $card->getStatus(), 'balance' => $card->getBalance()]);
        }
    }
}
