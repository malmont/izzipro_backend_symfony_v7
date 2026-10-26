<?php

namespace App\Services\PosStripeService;

use App\Command\PosTerminalReconcileCommand;
use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Entity\PosHealthAlert;
use App\Entity\PosTerminalPayment;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosTerminalService\PosTerminalSettingsService;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Surveillance des paiements de caisse (M1) : GET /api/pos/health (administrateur), bloc de la page EasyAdmin « Paiements de caisse
 * (Stripe) » et alertes par courriel envoyées en fin de réconciliation (timer systemd). Contrôles : mode Stripe et compte Connect,
 * dernière réconciliation (plus de 30 minutes dans sa plage horaire), paiements par terminal sans vente au-delà du délai réglé,
 * remboursements Interac en attente au-delà du délai ou expirés (24 h), sessions de caisse ouvertes depuis plus de 24 h, échecs de capture
 * (24 h), lecteur simulé en mode réel. Niveaux : ok < info < warning < critical. Aucune donnée de carte.
 */
class PosHealthService
{
    public const RECONCILE_STALE_MINUTES = 30;
    /** Même situation : pas de nouvel envoi avant 6 heures */
    public const RESEND_HOURS = 6;
    private const LEVELS = ['ok' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly PosStripeModeService $modes,
        private readonly PosTerminalSettingsService $terminalSettings,
        private readonly EmailSenderService $mailer,
        private readonly PosAuditService $audit,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment = 'dev',
    ) {
    }

    /** Rapport du site courant ; $withStripe = false : sans appel Stripe (état du compte non relu) */
    public function report(bool $withStripe = true): array
    {
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $e = $em->getRepository(Entreprise::class)->findOneBy([]);
        $overview = $this->modes->overview($withStripe);
        $mode = $overview['mode'];
        $terminal = $this->terminalSettings->settings();
        $checks = [];
        $usesTerminal = (bool) $connection->fetchOne("SELECT 1 FROM cash_register WHERE terminal_enabled = true OR stripe_reader_id IS NOT NULL OR terminal_mode IN ('tap_to_pay', 'bluetooth') LIMIT 1");

        // Compte Connect du mode de la caisse
        $account = $overview['accounts'][$mode];
        $status = $account['status'];
        if ($mode === StripeAccountResolver::LIVE) {
            if (!$overview['keys']['live']) {
                $checks[] = self::check('STRIPE_KEYS_MISSING', 'critical', 'Mode réel sans clés Stripe réelles configurées sur le serveur');
            } elseif (is_array($status) && isset($status['error'])) {
                $checks[] = self::check('STRIPE_ACCOUNT_UNREADABLE', 'warning', 'Compte Stripe réel illisible : ' . $status['error']);
            } elseif (is_array($status) && (!$status['chargesEnabled'] || !$status['payoutsEnabled'])) {
                $checks[] = self::check('STRIPE_ACCOUNT_NOT_READY', 'critical', 'Compte Stripe réel : encaissement ou virements suspendus (pièces à fournir)', 1,
                    ['chargesEnabled' => $status['chargesEnabled'], 'payoutsEnabled' => $status['payoutsEnabled'], 'currentlyDue' => $status['currentlyDue'], 'pastDue' => $status['pastDue'], 'disabledReason' => $status['disabledReason']]);
            } elseif (is_array($status) && ($status['currentlyDue'] !== [] || $status['pastDue'] !== [])) {
                $checks[] = self::check('STRIPE_ACCOUNT_REQUIREMENTS', 'warning', 'Compte Stripe réel : pièces demandées par Stripe', count($status['currentlyDue']) + count($status['pastDue']),
                    ['currentlyDue' => $status['currentlyDue'], 'pastDue' => $status['pastDue'], 'currentDeadline' => $status['currentDeadline']]);
            }
            foreach ($overview['registers'] as $r) {
                if ($r['reader']['simulated'] ?? false) {
                    $checks[] = self::check('SIMULATED_READER_IN_LIVE', 'critical', sprintf('Lecteur simulé sur le poste « %s » en mode réel', $r['name']));
                }
            }
        } elseif ($usesTerminal) {
            $checks[] = self::check('STRIPE_TEST_MODE', 'info', 'Paiements de caisse en mode test : aucun paiement réel');
        }

        // Dernière réconciliation (état daté écrit par la commande)
        $reconcile = $this->reconcileStatus();
        if ($usesTerminal && $reconcile['stale']) {
            $checks[] = self::check('RECONCILE_STALE', 'critical', $reconcile['lastCompletedAt'] === null ? 'Réconciliation jamais exécutée sur ce serveur' : sprintf('Réconciliation non exécutée depuis %d minutes', $reconcile['minutesAgo']), 1, ['lastCompletedAt' => $reconcile['lastCompletedAt']]);
        }

        // Paiements par terminal sans vente au-delà du délai réglé
        $orphans = $connection->fetchAllAssociative("SELECT id, amount, status, purpose, created_at FROM pos_terminal_payment WHERE order_id IS NULL AND status IN (:kept) AND created_at < :limit ORDER BY created_at DESC LIMIT 20",
            ['kept' => [PosTerminalPayment::CAPTURED, PosTerminalPayment::AUTHORIZED], 'limit' => (new \DateTimeImmutable('-' . $terminal['orphanMinutes'] . ' minutes'))->format('Y-m-d H:i:s')], ['kept' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        if ($orphans !== []) {
            $checks[] = self::check('ORPHAN_PAYMENTS', 'warning', sprintf('%d paiement(s) par terminal sans vente depuis plus de %d minutes', count($orphans), $terminal['orphanMinutes']), count($orphans),
                ['payments' => array_map(fn ($o) => ['paymentId' => (int) $o['id'], 'amount' => (int) $o['amount'], 'status' => $o['status'], 'purpose' => $o['purpose'], 'createdAt' => $o['created_at']], $orphans)]);
        }

        // Remboursements Interac en attente au-delà du délai (réconciliation en retard) ou expirés dans les 24 h
        $waiting = (int) $connection->fetchOne('SELECT COUNT(*) FROM pos_terminal_payment WHERE refund_status = :w AND COALESCE(refund_requested_at, updated_at) < :limit',
            ['w' => PosTerminalPayment::REFUND_WAITING, 'limit' => (new \DateTimeImmutable('-' . $terminal['refundPendingMinutes'] . ' minutes'))->format('Y-m-d H:i:s')]);
        $expired = $this->auditCount(PosAuditService::REFUND_EXPIRED);
        if ($waiting + $expired > 0) {
            $checks[] = self::check('REFUNDS_EXPIRED', 'warning', sprintf('Remboursements Interac : %d en attente au-delà de %d minutes, %d expiré(s) en 24 h', $waiting, $terminal['refundPendingMinutes'], $expired), $waiting + $expired,
                ['waitingBeyondDelay' => $waiting, 'expired24h' => $expired]);
        }

        // Sessions de caisse ouvertes depuis plus de 24 h
        $sessions = $connection->fetchAllAssociative("SELECT c.id, c.created_at, r.name FROM caisse c LEFT JOIN cash_register r ON r.id = c.cash_register_id WHERE c.is_open = true AND c.created_at < :limit ORDER BY c.created_at",
            ['limit' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]);
        if ($sessions !== []) {
            $checks[] = self::check('SESSIONS_OPEN_24H', 'warning', sprintf('%d session(s) de caisse ouverte(s) depuis plus de 24 h', count($sessions)), count($sessions),
                ['sessions' => array_map(fn ($s) => ['sessionId' => (int) $s['id'], 'cashRegister' => $s['name'], 'openedAt' => $s['created_at']], $sessions)]);
        }

        // Échecs de capture (24 h)
        $captures = (int) $connection->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = :t AND occurred_at > :since AND payload LIKE '%\"stage\":\"capture\"%'",
            ['t' => PosAuditService::CARD_PAYMENT_FAILED, 'since' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]);
        if ($captures > 0) {
            $checks[] = self::check('CAPTURE_FAILURES', 'critical', sprintf('%d échec(s) de capture d\'un paiement par terminal en 24 h', $captures), $captures);
        }

        $level = 'ok';
        foreach ($checks as $c) {
            if (self::LEVELS[$c['level']] > self::LEVELS[$level]) {
                $level = $c['level'];
            }
        }

        return [
            'status' => $level, 'checkedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'site' => (string) $this->tenants->getCurrentTenantCode(),
            'usesTerminal' => $usesTerminal,
            'stripe' => ['mode' => $mode, 'internal' => $overview['internal'], 'keys' => $overview['keys'], 'account' => $account, 'pendingPayments' => $overview['pendingPayments']],
            'reconcile' => $reconcile,
            'checks' => $checks,
            'alerts' => ['siteEnabled' => $e?->isPosAlertsEnabled() ?? false, 'siteRecipient' => self::maskEmail($this->siteRecipient($e)), 'platformRecipient' => $this->platformRecipient() !== null],
        ];
    }

    /** Dernière réconciliation : état daté de PosTerminalReconcileCommand (var/pos-terminal-reconcile.status.json) */
    public function reconcileStatus(): array
    {
        $path = PosTerminalReconcileCommand::statusPath($this->projectDir, $this->environment);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $last = is_array($data['last'] ?? null) ? $data['last'] : null;
        $completed = is_array($data['lastCompleted'] ?? null) ? $data['lastCompleted'] : null;
        $completedAt = $completed['finishedAt'] ?? null;
        $minutes = $completedAt !== null ? (int) floor((time() - strtotime($completedAt)) / 60) : null;
        // Hors de la plage horaire réglée, la commande ne fait rien : pas d'alerte (la dernière exécution « hors plage » est récente)
        $outsideWindow = ($last['outcome'] ?? null) === 'outside_window' && strtotime((string) $last['finishedAt']) > time() - self::RECONCILE_STALE_MINUTES * 60;

        return [
            'lastRunAt' => $last['finishedAt'] ?? null, 'lastOutcome' => $last['outcome'] ?? null, 'lastCompletedAt' => $completedAt, 'minutesAgo' => $minutes,
            'stale' => !$outsideWindow && ($minutes === null || $minutes > self::RECONCILE_STALE_MINUTES), 'staleAfterMinutes' => self::RECONCILE_STALE_MINUTES,
            'lastErrors' => $completed['errors'] ?? null,
        ];
    }

    /**
     * Alertes du site courant (fin de réconciliation) : contrôles warning ou critical, au contact du site (si activé) et à l'administrateur
     * de la plateforme (POS_ALERT_PLATFORM_EMAIL, facultatif) ; une même situation n'est pas renvoyée avant 6 heures ; situation résolue :
     * l'état est marqué résolu (pas de courriel). Rend les codes envoyés.
     *
     * @return list<string>
     */
    public function alert(?array $report = null): array
    {
        $report ??= $this->report(true);
        $em = $this->emProvider->getEntityManager();
        $e = $em->getRepository(Entreprise::class)->findOneBy([]);
        $recipients = array_values(array_unique(array_filter([($e?->isPosAlertsEnabled() ? $this->siteRecipient($e) : null), $this->platformRecipient()])));
        $repo = $em->getRepository(PosHealthAlert::class);
        $now = new \DateTimeImmutable();
        $active = [];
        $toSend = [];
        foreach ($report['checks'] as $check) {
            if (self::LEVELS[$check['level']] < self::LEVELS['warning']) {
                continue;
            }
            $active[$check['code']] = true;
            $fingerprint = hash('sha256', $check['code'] . '|' . $check['count'] . '|' . $check['level']);
            $state = $repo->findOneBy(['code' => $check['code']]);
            if ($state === null) {
                $state = new PosHealthAlert($check['code'], $check['level'], $fingerprint);
                $em->persist($state);
            } elseif ($state->getResolvedAt() !== null) {
                $state->setResolvedAt(null)->setFirstSeenAt($now)->setLastSentAt(null);
            }
            $changed = $state->getFingerprint() !== $fingerprint;
            $state->setFingerprint($fingerprint)->setLevel($check['level']);
            if ($state->getLastSentAt() === null || $changed && $state->getLastSentAt() < $now->modify('-1 hour') || $state->getLastSentAt() < $now->modify('-' . self::RESEND_HOURS . ' hours')) {
                $toSend[] = $check;
                if ($recipients !== []) {
                    $state->setLastSentAt($now);
                }
            }
        }
        foreach ($repo->findBy(['resolvedAt' => null]) as $state) {
            if (!isset($active[$state->getCode()])) {
                $state->setResolvedAt($now);
            }
        }
        $em->flush();
        if ($toSend === [] || $recipients === []) {
            return [];
        }
        $site = $e?->getName() ?: $report['site'];
        $subject = sprintf('[Caisse %s] %s : %d alerte(s)', $site, $report['status'] === 'critical' ? 'CRITIQUE' : 'attention', count($toSend));
        $sent = 0;
        foreach ($recipients as $to) {
            $sent += $this->mailer->sendPlatformEmail($to, $subject, 'emails/pos_health_alert.html.twig', ['site' => $site, 'siteCode' => $report['site'], 'checks' => $toSend, 'report' => $report]) ? 1 : 0;
        }
        $this->audit->record(PosAuditService::HEALTH_ALERT_SENT, ['codes' => array_column($toSend, 'code'), 'recipients' => count($recipients), 'sent' => $sent, 'status' => $report['status']], null, null, null, null, null, true);

        return array_column($toSend, 'code');
    }

    private function auditCount(string $type): int
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM pos_audit_log WHERE type = :t AND occurred_at > :since',
            ['t' => $type, 'since' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]);
    }

    private function siteRecipient(?Entreprise $e): ?string
    {
        $email = $e?->getPosAlertsEmail() ?? $this->emProvider->getEntityManager()->getRepository(AddressEntreprise::class)->findOneBy([])?->getEmail() ?? $e?->getEmail();

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Administrateur de la plateforme (variable d'environnement facultative, jamais modifiable par un administrateur de site) */
    private function platformRecipient(): ?string
    {
        $email = $_ENV['POS_ALERT_PLATFORM_EMAIL'] ?? $_SERVER['POS_ALERT_PLATFORM_EMAIL'] ?? getenv('POS_ALERT_PLATFORM_EMAIL');

        return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : null;
    }

    private static function check(string $code, string $level, string $message, int $count = 1, array $details = []): array
    {
        return ['code' => $code, 'level' => $level, 'message' => $message, 'count' => $count, 'details' => $details];
    }

    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 1) . '***@' . $domain;
    }
}
