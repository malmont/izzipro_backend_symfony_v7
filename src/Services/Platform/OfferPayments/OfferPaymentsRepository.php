<?php

namespace App\Services\Platform\OfferPayments;

use App\Services\TenantConnectionManager;

/**
 * Tables de la base maître du lot PROVISION-3 (18/11/2026, `ops/sql/platform_offer_payments_master.sql`) : journal des événements Stripe
 * des abonnements, périodes payées, file de reprise des droits. Aucun contenu d'événement : identifiants, dates, états.
 */
class OfferPaymentsRepository
{
    /** Paliers de la file de reprise, en secondes, selon le nombre d'échecs (le dernier se répète) */
    public const RETRY_STEPS = [300, 900, 3600, 10800, 21600];
    /** Un événement « reçu » depuis plus longtemps est tenu pour abandonné (traitement interrompu) : une nouvelle livraison le reprend */
    public const STALE_SECONDS = 300;
    public const KEEP_DAYS = 90;

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /**
     * Prend l'événement pour le traiter. Rend le nombre d'essais (1 au premier), ou null si l'événement est déjà traité, ignoré, ou en
     * cours ailleurs : il ne faut alors rien refaire.
     */
    public function claim(string $eventId, string $type, ?string $account, ?string $tenantCode, ?string $objectId, ?int $created): ?int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo()->prepare('INSERT INTO stripe_event_log (event_id, type, account, tenant_code, object_id, event_created, received_at, status)
            VALUES (:id, :type, :account, :tenant, :object, :created, :now, \'received\')
            ON CONFLICT (event_id) DO UPDATE SET attempts = stripe_event_log.attempts + 1, status = \'received\', received_at = EXCLUDED.received_at
            WHERE stripe_event_log.status = \'failed\' OR (stripe_event_log.status = \'received\' AND stripe_event_log.received_at < :stale)
               OR (stripe_event_log.status = \'ignored\' AND stripe_event_log.detail IN (\'abonnement inconnu\', \'site inconnu\'))
            RETURNING attempts');
        $stmt->execute(['id' => mb_substr($eventId, 0, 255), 'type' => mb_substr($type, 0, 80), 'account' => $account !== null ? mb_substr($account, 0, 64) : null,
            'tenant' => $tenantCode !== null ? mb_substr($tenantCode, 0, 50) : null, 'object' => $objectId !== null && $objectId !== '' ? mb_substr($objectId, 0, 64) : null,
            'created' => $created !== null ? gmdate('Y-m-d H:i:s', $created) : null, 'now' => $now, 'stale' => gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS)]);
        $attempts = $stmt->fetchColumn();

        return $attempts === false ? null : (int) $attempts;
    }

    /** L'état d'un événement du journal, ou null s'il est inconnu */
    public function status(string $eventId): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT status FROM stripe_event_log WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    /** La facture est-elle notée payée ? */
    public function periodPaidExists(string $tenantCode, string $invoiceId): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM subscription_period_paid WHERE tenant_code = ? AND invoice_id = ?');
        $stmt->execute([$tenantCode, $invoiceId]);

        return $stmt->fetchColumn() !== false;
    }

    /** `processed` ou `ignored`, avec un détail court */
    public function finish(string $eventId, string $status, ?string $detail): void
    {
        $this->pdo()->prepare('UPDATE stripe_event_log SET status = ?, detail = ?, processed_at = ?, last_error_class = NULL WHERE event_id = ?')
            ->execute([$status, $detail !== null ? mb_substr($detail, 0, 120) : null, gmdate('Y-m-d H:i:s'), $eventId]);
    }

    /** En échec : repris à la livraison suivante (`$error` vide : renvoyé à Stripe sans erreur, par exemple abonnement pas encore enregistré) */
    public function fail(string $eventId, ?\Throwable $error): void
    {
        $this->pdo()->prepare('UPDATE stripe_event_log SET status = \'failed\', last_error_class = ? WHERE event_id = ?')->execute([$error !== null ? mb_substr($error::class, 0, 160) : null, $eventId]);
    }

    /** @return list<array<string, mixed>> les derniers événements, pour l'écran « Paiements des offres » */
    public function recentEvents(int $limit = 50): array
    {
        $stmt = $this->pdo()->prepare('SELECT event_id, type, tenant_code, object_id, event_created, received_at, status, detail, attempts, last_error_class FROM stripe_event_log ORDER BY received_at DESC, event_id LIMIT ?');
        $stmt->bindValue(1, max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Efface les événements de plus de 90 jours ; rend le nombre de lignes effacées */
    public function purgeEvents(): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM stripe_event_log WHERE received_at < ?');
        $stmt->execute([gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);

        return $stmt->rowCount();
    }

    /** Note la facture payée ; true seulement la première fois (l'événement « période payée » n'est émis qu'alors) */
    public function recordPeriodPaid(string $tenantCode, string $invoiceId, int $subscriptionId, ?int $periodStart, ?int $periodEnd, int $amountPaid, ?string $billingReason): bool
    {
        $stmt = $this->pdo()->prepare('INSERT INTO subscription_period_paid (tenant_code, invoice_id, subscription_id, period_start, period_end, amount_paid, billing_reason, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (tenant_code, invoice_id) DO NOTHING RETURNING invoice_id');
        $stmt->execute([$tenantCode, mb_substr($invoiceId, 0, 255), $subscriptionId, $periodStart !== null ? gmdate('Y-m-d H:i:s', $periodStart) : null,
            $periodEnd !== null ? gmdate('Y-m-d H:i:s', $periodEnd) : null, $amountPaid, $billingReason !== null ? mb_substr($billingReason, 0, 40) : null, gmdate('Y-m-d H:i:s')]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Lot ABO-1 : nombre de périodes PAYÉES d'un abonnement, pour l'engagement minimal : création et cycles, et un changement de formule qui
     * ouvre une période (période notée) ; jamais un prorata pur ni une facture à zéro (essai gratuit). Seules les périodes notées depuis
     * PROVISION-3 sont comptées (simplification dite dans la fiche).
     */
    public function paidPeriodsCount(string $tenantCode, int $subscriptionId): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM subscription_period_paid WHERE tenant_code = ? AND subscription_id = ? AND amount_paid > 0
            AND (COALESCE(billing_reason, 'subscription_cycle') IN ('subscription_create', 'subscription_cycle') OR (billing_reason = 'subscription_update' AND period_start IS NOT NULL))");
        $stmt->execute([$tenantCode, $subscriptionId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lot ABO-1 : les périodes payées d'un site que leurs écouteurs n'ont pas traitées (écouteur en échec, processus tombé), notées depuis
     * plus de 10 minutes (le webhook en cours n'est pas doublé), à réémettre par le passage quotidien.
     *
     * @return list<array{invoice_id: string, subscription_id: int, period_start: ?string, period_end: ?string, amount_paid: int, billing_reason: ?string}>
     */
    public function undispatchedPeriods(string $tenantCode, int $limit = 500): array
    {
        $stmt = $this->pdo()->prepare('SELECT invoice_id, subscription_id, period_start, period_end, amount_paid, billing_reason FROM subscription_period_paid
            WHERE tenant_code = ? AND dispatched_at IS NULL AND recorded_at < ? ORDER BY recorded_at LIMIT ?');
        $stmt->bindValue(1, $tenantCode);
        $stmt->bindValue(2, gmdate('Y-m-d H:i:s', time() - 600));
        $stmt->bindValue(3, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r) => ['invoice_id' => (string) $r['invoice_id'], 'subscription_id' => (int) $r['subscription_id'], 'period_start' => $r['period_start'],
            'period_end' => $r['period_end'], 'amount_paid' => (int) $r['amount_paid'], 'billing_reason' => $r['billing_reason']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** L'événement « période payée » a été traité par ses écouteurs (une période notée sans cette date reste à réémettre, lot ABO-1) */
    public function markPeriodDispatched(string $tenantCode, string $invoiceId): void
    {
        $this->pdo()->prepare('UPDATE subscription_period_paid SET dispatched_at = ? WHERE tenant_code = ? AND invoice_id = ?')->execute([gmdate('Y-m-d H:i:s'), $tenantCode, $invoiceId]);
    }

    /** Met l'abonnement dans la file de reprise (une ligne par abonnement ; une ligne déjà là garde ses essais et sa prochaine reprise) */
    public function schedule(string $tenantCode, int $subscriptionId, ?string $errorClass): void
    {
        $now = time();
        $this->pdo()->prepare('INSERT INTO platform_offer_retry (tenant_code, subscription_id, attempts, next_retry_at, last_error_class, created_at, updated_at)
            VALUES (?, ?, 0, ?, ?, ?, ?)
            ON CONFLICT (tenant_code, subscription_id) DO UPDATE SET last_error_class = COALESCE(EXCLUDED.last_error_class, platform_offer_retry.last_error_class), updated_at = EXCLUDED.updated_at')
            ->execute([$tenantCode, $subscriptionId, gmdate('Y-m-d H:i:s', $now + self::RETRY_STEPS[0]), $errorClass !== null ? mb_substr($errorClass, 0, 160) : null, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now)]);
    }

    /** @return list<array{id: int, tenant_code: string, subscription_id: int, attempts: int, alerted: bool}> */
    public function dueRetries(int $limit = 50): array
    {
        $stmt = $this->pdo()->prepare('SELECT id, tenant_code, subscription_id, attempts, alerted FROM platform_offer_retry WHERE next_retry_at <= ? ORDER BY next_retry_at, id LIMIT ?');
        $stmt->bindValue(1, gmdate('Y-m-d H:i:s'));
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'tenant_code' => (string) $r['tenant_code'], 'subscription_id' => (int) $r['subscription_id'],
            'attempts' => (int) $r['attempts'], 'alerted' => filter_var($r['alerted'], FILTER_VALIDATE_BOOLEAN)], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return ?array{id: int, tenant_code: string, subscription_id: int, attempts: int, alerted: bool} */
    public function retry(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id, tenant_code, subscription_id, attempts, alerted FROM platform_offer_retry WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $r === false ? null : ['id' => (int) $r['id'], 'tenant_code' => (string) $r['tenant_code'], 'subscription_id' => (int) $r['subscription_id'],
            'attempts' => (int) $r['attempts'], 'alerted' => filter_var($r['alerted'], FILTER_VALIDATE_BOOLEAN)];
    }

    /** @return list<array<string, mixed>> toute la file, pour l'écran */
    public function allRetries(): array
    {
        return $this->pdo()->query('SELECT id, tenant_code, subscription_id, attempts, next_retry_at, last_error_class, alerted, created_at, updated_at FROM platform_offer_retry ORDER BY next_retry_at, id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function retrySucceeded(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM platform_offer_retry WHERE id = ?')->execute([$id]);
    }

    /** Un échec de plus : prochaine reprise au palier suivant ; rend le nombre d'échecs */
    public function retryFailed(int $id, \Throwable $error): int
    {
        $stmt = $this->pdo()->prepare('UPDATE platform_offer_retry SET attempts = attempts + 1, last_error_class = ?, updated_at = ? WHERE id = ? RETURNING attempts');
        $stmt->execute([mb_substr($error::class, 0, 160), gmdate('Y-m-d H:i:s'), $id]);
        $attempts = (int) $stmt->fetchColumn();
        $step = self::RETRY_STEPS[min(max(0, $attempts), count(self::RETRY_STEPS) - 1)];
        $this->pdo()->prepare('UPDATE platform_offer_retry SET next_retry_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() + $step), $id]);

        return $attempts;
    }

    public function markAlerted(int $id): void
    {
        $this->pdo()->prepare('UPDATE platform_offer_retry SET alerted = true WHERE id = ?')->execute([$id]);
    }

    private function pdo(): \PDO
    {
        return $this->tenants->getPdoMaster();
    }
}
