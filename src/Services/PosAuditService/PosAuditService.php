<?php

namespace App\Services\PosAuditService;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Écriture du journal d'audit de la caisse (chantier C2, 19/10/2026). Une entrée par événement, dans la transaction SQL
 * de l'événement (sinon dans la sienne), sous verrou consultatif du site : séquence continue sans trou, empreinte chaînée
 * hash = SHA-256(prevHash + payload). Ne concerne que la caisse (la boutique en ligne n'écrit rien ici).
 */
class PosAuditService
{
    public const LOGIN = 'LOGIN';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGOUT = 'LOGOUT';
    public const SESSION_OPENED = 'SESSION_OPENED';
    public const SESSION_CLOSED = 'SESSION_CLOSED';
    public const CASH_DEPOSIT = 'CASH_DEPOSIT';
    public const CASH_WITHDRAWAL = 'CASH_WITHDRAWAL';
    public const FUND_DEPOSIT = 'FUND_DEPOSIT';
    public const FUND_WITHDRAWAL = 'FUND_WITHDRAWAL';
    public const SALE = 'SALE';
    public const REFUND = 'REFUND';
    public const Z_REPORT_CREATED = 'Z_REPORT_CREATED';
    public const X_REPORT_VIEWED = 'X_REPORT_VIEWED';
    public const CLOSING_PREVIEW = 'CLOSING_PREVIEW';
    public const DRAWER_OPENED = 'DRAWER_OPENED';
    public const CASH_REGISTER_CREATED = 'CASH_REGISTER_CREATED';
    public const CASH_REGISTER_UPDATED = 'CASH_REGISTER_UPDATED';
    public const DISCOUNT_APPLIED = 'DISCOUNT_APPLIED'; // réservé (chantier F1)
    public const PRICE_CHANGED = 'PRICE_CHANGED';       // prix manuel sur une ligne de vente (F7, 26/10/2026) : avant / après, motif
    // PIN et validations (C3, 20/10/2026)
    public const PIN_SET = 'PIN_SET';
    public const PIN_FAILED = 'PIN_FAILED';
    public const PIN_LOCKED = 'PIN_LOCKED';
    public const PIN_RESET = 'PIN_RESET';
    public const UNLOCK = 'UNLOCK';
    public const APPROVAL_GRANTED = 'APPROVAL_GRANTED';
    public const APPROVAL_FAILED = 'APPROVAL_FAILED';
    // Terminal de paiement Stripe (C4, 23/10/2026) : jamais de donnée de carte au-delà de la marque et des 4 derniers chiffres
    public const CARD_PAYMENT_STARTED = 'CARD_PAYMENT_STARTED';
    public const CARD_PAYMENT_SUCCEEDED = 'CARD_PAYMENT_SUCCEEDED';
    public const CARD_PAYMENT_FAILED = 'CARD_PAYMENT_FAILED';
    public const CARD_PAYMENT_CANCELED = 'CARD_PAYMENT_CANCELED';
    public const CARD_REFUND = 'CARD_REFUND';
    public const CARD_SIMULATED = 'CARD_SIMULATED';
    public const TERMINAL_CONFIGURED = 'TERMINAL_CONFIGURED';
    // Kit Stripe Terminal dans l'application (F13/F16, 31/10/2026)
    public const CONNECTION_TOKEN_ISSUED = 'CONNECTION_TOKEN_ISSUED';
    public const REFUND_PENDING = 'REFUND_PENDING';
    public const REFUND_CONFIRMED = 'REFUND_CONFIRMED';
    public const REFUND_EXPIRED = 'REFUND_EXPIRED';
    // Coupons de fabricant (F17, 01/11/2026)
    public const MANUFACTURER_COUPON_REDEEMED = 'MANUFACTURER_COUPON_REDEEMED';
    public const MANUFACTURER_COUPON_REFUSED = 'MANUFACTURER_COUPON_REFUSED';
    public const MANUFACTURER_COUPON_VOIDED = 'MANUFACTURER_COUPON_VOIDED';
    public const MANUFACTURER_COUPONS_CLAIMED = 'MANUFACTURER_COUPONS_CLAIMED';
    // Ticket numérique (F3, 24/10/2026) : réimpression, envoi par courriel (adresse masquée seulement), révocation du lien public
    public const RECEIPT_REPRINTED = 'RECEIPT_REPRINTED';
    public const RECEIPT_EMAILED = 'RECEIPT_EMAILED';
    public const RECEIPT_LINK_REVOKED = 'RECEIPT_LINK_REVOKED';
    public const OFFLINE_SYNC = 'OFFLINE_SYNC'; // lot de ventes hors ligne synchronisé (C5 partie 2, 25/10/2026)
    // Cartes-cadeaux et avoirs (F9, 27/10/2026)
    public const GIFT_CARD_SOLD = 'GIFT_CARD_SOLD';
    public const GIFT_CARD_REDEEMED = 'GIFT_CARD_REDEEMED';
    public const GIFT_CARD_LOOKUP = 'GIFT_CARD_LOOKUP';
    public const GIFT_CARD_CANCELED = 'GIFT_CARD_CANCELED';
    public const GIFT_CARD_ADJUSTED = 'GIFT_CARD_ADJUSTED';
    public const GIFT_CARD_CASH_OUT = 'GIFT_CARD_CASH_OUT';
    public const GIFT_CARD_REPLACED = 'GIFT_CARD_REPLACED';
    public const GIFT_CARD_REFUNDED = 'GIFT_CARD_REFUNDED'; // recrédit lors d'un retour
    public const STORE_CREDIT_ISSUED = 'STORE_CREDIT_ISSUED';
    // Fiche client et fidélité (F8, 28/10/2026)
    public const CUSTOMER_SEARCHED = 'CUSTOMER_SEARCHED';
    public const CUSTOMER_VIEWED = 'CUSTOMER_VIEWED';
    public const CUSTOMER_CREATED = 'CUSTOMER_CREATED';
    public const CUSTOMER_UPDATED = 'CUSTOMER_UPDATED';
    public const CUSTOMER_LINKED = 'CUSTOMER_LINKED';
    public const CUSTOMER_MERGED = 'CUSTOMER_MERGED';
    public const CUSTOMER_ERASED = 'CUSTOMER_ERASED';
    public const CUSTOMER_EXPORTED = 'CUSTOMER_EXPORTED';
    public const LOYALTY_EARNED = 'LOYALTY_EARNED';
    public const LOYALTY_REDEEMED = 'LOYALTY_REDEEMED';
    public const LOYALTY_RETURNED = 'LOYALTY_RETURNED';
    public const LOYALTY_ADJUSTED = 'LOYALTY_ADJUSTED';
    public const LOYALTY_EXPIRED = 'LOYALTY_EXPIRED';
    // Statistiques par caissier et exports comptables (F14, 29/10/2026)
    public const CASHIER_STATS_VIEWED = 'CASHIER_STATS_VIEWED';
    public const EXPORT_GENERATED = 'EXPORT_GENERATED';
    // Conservation et archive (A1, 30/10/2026)
    public const RETENTION_WRITE_OBSERVED = 'RETENTION_WRITE_OBSERVED';
    public const ARCHIVE_EXPORTED = 'ARCHIVE_EXPORTED';
    // Grille de favoris (F10) : épinglage par le responsable (site ou poste)
    public const FAVORITES_UPDATED = 'FAVORITES_UPDATED';
    /** Entrées d'une action qui peut avoir été validée par un responsable : elles portent data.approvedBy */
    private const APPROVABLE = [self::REFUND, self::CASH_WITHDRAWAL, self::FUND_WITHDRAWAL, self::DRAWER_OPENED, self::SESSION_CLOSED, self::DISCOUNT_APPLIED, self::PRICE_CHANGED,
        self::GIFT_CARD_CANCELED, self::GIFT_CARD_ADJUSTED, self::GIFT_CARD_CASH_OUT, self::GIFT_CARD_REPLACED, self::LOYALTY_ADJUSTED, self::CUSTOMER_ERASED];

    public const TYPES = [self::LOGIN, self::LOGIN_FAILED, self::LOGOUT, self::SESSION_OPENED, self::SESSION_CLOSED, self::CASH_DEPOSIT,
        self::CASH_WITHDRAWAL, self::FUND_DEPOSIT, self::FUND_WITHDRAWAL, self::SALE, self::REFUND, self::Z_REPORT_CREATED, self::X_REPORT_VIEWED,
        self::CLOSING_PREVIEW, self::DRAWER_OPENED, self::CASH_REGISTER_CREATED, self::CASH_REGISTER_UPDATED, self::DISCOUNT_APPLIED, self::PRICE_CHANGED,
        self::PIN_SET, self::PIN_FAILED, self::PIN_LOCKED, self::PIN_RESET, self::UNLOCK, self::APPROVAL_GRANTED, self::APPROVAL_FAILED,
        self::CARD_PAYMENT_STARTED, self::CARD_PAYMENT_SUCCEEDED, self::CARD_PAYMENT_FAILED, self::CARD_PAYMENT_CANCELED, self::CARD_REFUND, self::CARD_SIMULATED, self::TERMINAL_CONFIGURED, self::CONNECTION_TOKEN_ISSUED, self::REFUND_PENDING, self::REFUND_CONFIRMED, self::REFUND_EXPIRED,
        self::MANUFACTURER_COUPON_REDEEMED, self::MANUFACTURER_COUPON_REFUSED, self::MANUFACTURER_COUPON_VOIDED, self::MANUFACTURER_COUPONS_CLAIMED,
        self::RECEIPT_REPRINTED, self::RECEIPT_EMAILED, self::RECEIPT_LINK_REVOKED, self::OFFLINE_SYNC,
        self::GIFT_CARD_SOLD, self::GIFT_CARD_REDEEMED, self::GIFT_CARD_LOOKUP, self::GIFT_CARD_CANCELED, self::GIFT_CARD_ADJUSTED, self::GIFT_CARD_CASH_OUT, self::GIFT_CARD_REPLACED, self::GIFT_CARD_REFUNDED, self::STORE_CREDIT_ISSUED,
        self::CUSTOMER_SEARCHED, self::CUSTOMER_VIEWED, self::CUSTOMER_CREATED, self::CUSTOMER_UPDATED, self::CUSTOMER_LINKED, self::CUSTOMER_MERGED, self::CUSTOMER_ERASED, self::CUSTOMER_EXPORTED,
        self::LOYALTY_EARNED, self::LOYALTY_REDEEMED, self::LOYALTY_RETURNED, self::LOYALTY_ADJUSTED, self::LOYALTY_EXPIRED, self::CASHIER_STATS_VIEWED, self::EXPORT_GENERATED, self::RETENTION_WRITE_OBSERVED, self::ARCHIVE_EXPORTED, self::FAVORITES_UPDATED];

    /** Clé du verrou consultatif (par base, donc par site) */
    private const LOCK_KEY = 7461002;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly Security $security, private readonly PosTerminalService $terminals, private readonly ?PosApprovalContext $approvals = null)
    {
    }

    /**
     * @param array<string, mixed> $data données utiles (avant / après), montants en cents
     * @param ?User $user auteur ; par défaut l'utilisateur connecté (aucun pour LOGIN_FAILED)
     * @return array{id: int, sequence: int, occurredAt: string, hash: string}
     */
    public function record(string $type, array $data = [], ?Caisse $session = null, ?CashRegister $register = null, ?string $subjectType = null, ?int $subjectId = null, ?User $user = null, bool $anonymous = false): array
    {
        $user ??= $anonymous ? null : ($this->security->getUser() instanceof User ? $this->security->getUser() : null);
        if (in_array($type, self::APPROVABLE, true) && $this->approvals?->approvedBy() !== null && !array_key_exists('approvedBy', $data)) {
            $data['approvedBy'] = $this->approvals->approvedBy(); // validation d'un responsable consommée par cette action (C3)
        }
        $register ??= $session?->getCashRegister();
        $terminal = $this->terminals->current();
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $own = !$connection->isTransactionActive();
        if ($own) {
            $connection->beginTransaction();
        }
        try {
            $connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            $last = $connection->fetchAssociative('SELECT sequence, hash FROM pos_audit_log ORDER BY sequence DESC LIMIT 1');
            $sequence = $last ? (int) $last['sequence'] + 1 : 1;
            $prevHash = $last ? (string) $last['hash'] : null;
            $occurredAt = new \DateTimeImmutable();
            $payload = self::canonical([
                'sequence' => $sequence,
                'type' => $type,
                'occurredAt' => $occurredAt->format(\DateTimeInterface::ATOM),
                'user' => $user ? ['id' => $user->getId(), 'email' => $user->getEmail(), 'roles' => array_values($user->getRoles())] : null,
                'cashRegisterId' => $register?->getId(),
                'sessionId' => $session?->getId(),
                'terminal' => $terminal,
                'subject' => $subjectType !== null ? ['type' => $subjectType, 'id' => $subjectId] : null,
                'data' => $data,
            ]);
            $hash = hash('sha256', ($prevHash ?? '') . $payload);
            $connection->insert('pos_audit_log', [
                'sequence' => $sequence, 'type' => $type, 'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'user_id' => $user?->getId(), 'cash_register_id' => $register?->getId(), 'session_id' => $session?->getId(),
                'terminal_id' => $terminal['id'] ?? null, 'payload' => $payload, 'prev_hash' => $prevHash, 'hash' => $hash,
            ]);
            $id = (int) $connection->lastInsertId();
            if ($own) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'sequence' => $sequence, 'occurredAt' => $occurredAt->format(\DateTimeInterface::ATOM), 'hash' => $hash];
    }

    /**
     * Contrôle du chaînage : séquence continue depuis 1, empreinte précédente égale à celle de l'entrée d'avant,
     * empreinte recalculée identique, colonnes cohérentes avec le contenu haché.
     *
     * @return array{status: string, checked: int, firstBroken: ?array{sequence: int, id: int, reason: string}}
     */
    public function verify(): array
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $checked = 0;
        $expectedSequence = 1;
        $prevHash = null;
        $after = 0;
        do {
            $rows = $connection->fetchAllAssociative('SELECT id, sequence, type, payload, prev_hash, hash FROM pos_audit_log WHERE sequence > ? ORDER BY sequence ASC LIMIT 1000', [$after]);
            foreach ($rows as $row) {
                $sequence = (int) $row['sequence'];
                $payload = json_decode((string) $row['payload'], true);
                $reason = match (true) {
                    $sequence !== $expectedSequence => 'séquence interrompue (attendu ' . $expectedSequence . ')',
                    $row['prev_hash'] !== $prevHash => 'empreinte précédente différente',
                    hash('sha256', ($prevHash ?? '') . $row['payload']) !== $row['hash'] => 'empreinte recalculée différente',
                    !is_array($payload) || ($payload['sequence'] ?? null) !== $sequence || ($payload['type'] ?? null) !== $row['type'] => 'colonnes différentes du contenu haché',
                    default => null,
                };
                if ($reason !== null) {
                    return ['status' => 'broken', 'checked' => $checked, 'firstBroken' => ['sequence' => $sequence, 'id' => (int) $row['id'], 'reason' => $reason]];
                }
                $checked++;
                $expectedSequence++;
                $prevHash = (string) $row['hash'];
                $after = $sequence;
            }
        } while (count($rows) === 1000);

        return ['status' => 'intact', 'checked' => $checked, 'firstBroken' => null];
    }

    /** JSON canonique (clés dans l'ordre d'écriture, sans échappement des barres ni de l'Unicode) */
    public static function canonical(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
