<?php

namespace App\Services\LandingAiService;

use App\Entity\AiCreditSetting;
use App\Entity\AiUsage;
use App\Repository\AiCreditSettingRepository;
use App\Repository\AiUsageRepository;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Crédits mensuels de l'assistant IA par tenant (mois calendaire, fuseau America/Toronto) et historique.
 *
 * Réservation atomique avant l'appel à l'IA : verrou PostgreSQL (pg_advisory_xact_lock) par base tenant, de sorte
 * que deux demandes simultanées au bord du quota ne le dépassent pas. Consommation au succès, libération à l'échec,
 * réservation orpheline (processus interrompu) libérée à son échéance : 5 minutes pour une demande synchrone, 35 minutes
 * pour une tâche de fond (attente dans la file, puis 300 s de traitement au plus). Lignes supprimées après 90 jours.
 */
final class LandingAiQuotaService
{
    public const TIMEZONE = 'America/Toronto';
    public const COSTS = ['edit' => 1, 'create' => 3, 'page' => 10];
    public const IMAGES_COST = 10;
    public const RESERVATION_TTL_SECONDS = 300;
    public const JOB_RESERVATION_TTL_SECONDS = 2100;
    public const RETENTION_DAYS = 90;
    /**
     * Demandes échouées après un appel réel à l'IA, par site et sur 24 h glissantes : au-delà, refus (429). Un échec
     * libère les crédits mais les appels sont payés : sans ce plafond, seule la limite par minute les bornait.
     */
    public const FAILED_REQUESTS_PER_DAY = 20;
    /** Clé du verrou consultatif (propre à la base du tenant) */
    public const LOCK_KEY = 7414201;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider
    ) {
    }

    public static function cost(string $mode, bool $withImages = false): int
    {
        return $withImages ? self::IMAGES_COST : (self::COSTS[$mode] ?? self::IMAGES_COST);
    }

    /**
     * @throws LandingAiException 402 si le quota du mois ne couvre pas le coût
     */
    public function reserve(string $mode, string $componentKey, int $cost, ?string $user, string $prompt, int $ttlSeconds = self::RESERVATION_TTL_SECONDS): AiUsage
    {
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $now = new \DateTimeImmutable();

        $connection->beginTransaction();
        try {
            $connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $usages = $this->usages();
            $usages->expireReservations($now);
            $usages->deleteOlderThan($now->modify(sprintf('-%d days', self::RETENTION_DAYS)));

            $dayAgo = $now->modify('-1 day');
            if ($usages->countFailedWithCallsSince($dayAgo) >= self::FAILED_REQUESTS_PER_DAY) {
                $connection->rollBack();
                $retryAfter = max(60, ($usages->oldestFailedWithCallsSince($dayAgo)?->getTimestamp() ?? $now->getTimestamp()) + 86400 - $now->getTimestamp());
                throw new LandingAiException(429, 'Trop d\'échecs', sprintf(
                    'L\'assistant a échoué %d fois en 24 h sur ce site : nouvelles demandes suspendues pendant environ %d minute(s). Vérifiez la section concernée ou reformulez la demande.',
                    self::FAILED_REQUESTS_PER_DAY, (int) ceil($retryAfter / 60)
                ), [], ['Retry-After' => (string) $retryAfter]);
            }

            $credits = $this->credits();
            if ($credits['used'] + $cost > $credits['monthly']) {
                $connection->rollBack();
                throw new LandingAiException(402, 'Quota épuisé', sprintf(
                    'Crédits IA insuffisants : cette demande coûte %d crédit(s), il en reste %d ce mois-ci (renouvellement le %s).',
                    $cost, $credits['remaining'], (new \DateTimeImmutable($credits['resetAt']))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y')
                ));
            }

            $usage = (new AiUsage())
                ->setTenant((string) ($this->tenantProvider->getTenantCode() ?? ''))
                ->setUser($user)
                ->setMode($mode)
                ->setComponentKey($componentKey)
                ->setCredits($cost)
                ->setStatus(AiUsage::STATUS_RESERVED)
                ->setReservedUntil($now->modify(sprintf('+%d seconds', $ttlSeconds)))
                ->setPromptExcerpt($prompt);
            $em->persist($usage);
            $em->flush();
            $connection->commit();

            return $usage;
        } catch (LandingAiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /** Succès : crédits consommés */
    public function complete(AiUsage $usage, LandingAiUsageStats $stats): void
    {
        $this->finish($usage, AiUsage::STATUS_SUCCESS, $stats);
    }

    /** Échec : crédits libérés (le statut failed n'est pas compté) */
    public function release(AiUsage $usage, ?LandingAiUsageStats $stats): void
    {
        $this->finish($usage, AiUsage::STATUS_FAILED, $stats);
    }

    /** @return array{monthly: int, used: int, remaining: int, resetAt: string} */
    public function credits(): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00', $tz);
        $resetAt = $monthStart->modify('first day of next month');
        $utc = new \DateTimeZone(date_default_timezone_get());

        $monthly = $this->settings()->monthlyCredits();
        $used = $this->usages()->sumCommittedCredits($monthStart->setTimezone($utc), new \DateTimeImmutable());

        return [
            'monthly' => $monthly,
            'used' => $used,
            'remaining' => max(0, $monthly - $used),
            'resetAt' => $resetAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Début du mois de facturation (fuseau TIMEZONE), exprimé dans le fuseau du serveur */
    public function monthStart(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** @return AiUsage[] demandes du mois en cours ayant appelé l'IA, réussies ou non (toutes sont facturées) */
    public function monthUsages(): array
    {
        return $this->usages()->findWithCallsSince($this->monthStart());
    }

    /** @return list<array> les dernières demandes du tenant */
    public function history(int $limit = 50): array
    {
        return array_map(fn (AiUsage $u) => [
            'id' => $u->getId(),
            'createdAt' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user' => $u->getUser(),
            'mode' => $u->getMode(),
            'componentKey' => $u->getComponentKey(),
            'status' => $u->getStatus(),
            'credits' => $u->getCredits(),
            'model' => $u->getModel(),
            'attempts' => $u->getAttempts(),
            'durationMs' => $u->getDurationMs(),
            'promptExcerpt' => $u->getPromptExcerpt(),
        ], $this->usages()->findLatest($limit));
    }

    private function finish(AiUsage $usage, string $status, ?LandingAiUsageStats $stats): void
    {
        $em = $this->emProvider->getEntityManager();
        $usage = $em->contains($usage) ? $usage : ($em->find(AiUsage::class, $usage->getId()) ?? $usage);
        $usage->setStatus($status)->setCompletedAt(new \DateTimeImmutable());
        if ($stats !== null) {
            $usage->setModel($stats->model)
                ->setAttempts($stats->attempts)
                ->setInputTokens($stats->inputTokens)
                ->setOutputTokens($stats->outputTokens)
                ->setCacheReadTokens($stats->cacheReadTokens)
                ->setCacheWriteTokens($stats->cacheWriteTokens)
                ->setDurationMs($stats->durationMs);
        }
        $em->flush();
    }

    private function usages(): AiUsageRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiUsage::class);
    }

    private function settings(): AiCreditSettingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiCreditSetting::class);
    }
}
