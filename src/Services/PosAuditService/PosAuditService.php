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
    public const PRICE_CHANGED = 'PRICE_CHANGED';       // réservé
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
    // Ticket numérique (F3, 24/10/2026) : réimpression, envoi par courriel (adresse masquée seulement), révocation du lien public
    public const RECEIPT_REPRINTED = 'RECEIPT_REPRINTED';
    public const RECEIPT_EMAILED = 'RECEIPT_EMAILED';
    public const RECEIPT_LINK_REVOKED = 'RECEIPT_LINK_REVOKED';
    /** Entrées d'une action qui peut avoir été validée par un responsable : elles portent data.approvedBy */
    private const APPROVABLE = [self::REFUND, self::CASH_WITHDRAWAL, self::FUND_WITHDRAWAL, self::DRAWER_OPENED, self::SESSION_CLOSED, self::DISCOUNT_APPLIED];

    public const TYPES = [self::LOGIN, self::LOGIN_FAILED, self::LOGOUT, self::SESSION_OPENED, self::SESSION_CLOSED, self::CASH_DEPOSIT,
        self::CASH_WITHDRAWAL, self::FUND_DEPOSIT, self::FUND_WITHDRAWAL, self::SALE, self::REFUND, self::Z_REPORT_CREATED, self::X_REPORT_VIEWED,
        self::CLOSING_PREVIEW, self::DRAWER_OPENED, self::CASH_REGISTER_CREATED, self::CASH_REGISTER_UPDATED, self::DISCOUNT_APPLIED, self::PRICE_CHANGED,
        self::PIN_SET, self::PIN_FAILED, self::PIN_LOCKED, self::PIN_RESET, self::UNLOCK, self::APPROVAL_GRANTED, self::APPROVAL_FAILED,
        self::CARD_PAYMENT_STARTED, self::CARD_PAYMENT_SUCCEEDED, self::CARD_PAYMENT_FAILED, self::CARD_PAYMENT_CANCELED, self::CARD_REFUND, self::CARD_SIMULATED, self::TERMINAL_CONFIGURED,
        self::RECEIPT_REPRINTED, self::RECEIPT_EMAILED, self::RECEIPT_LINK_REVOKED];

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
