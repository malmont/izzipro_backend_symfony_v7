<?php

namespace App\Services\LandingAiService;

use App\Entity\AiJob;
use App\Entity\AiUsage;
use App\Repository\AiJobRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Tâches de fond de l'assistant IA (base du tenant courant) : création, cycle de vie, nettoyage.
 * Résultat conservé 1 heure ; tâche bloquée (worker arrêté ou interrompu) passée en échec, crédits libérés.
 */
final class LandingAiJobService
{
    public const RESULT_TTL_SECONDS = 3600;
    /** En attente depuis plus de 30 minutes : le worker ne tourne pas */
    public const PENDING_TIMEOUT_SECONDS = 1800;
    /** En cours depuis plus de 10 minutes (traitement limité à 300 s) : worker interrompu */
    public const RUNNING_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingAiQuotaService $quota
    ) {
    }

    public function create(AiUsage $usage, ?string $user, string $input): AiJob
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

        $job = new AiJob($id, (int) $usage->getId(), $user, $input);
        $em = $this->emProvider->getEntityManager();
        $em->persist($job);
        $em->flush();

        return $job;
    }

    /** Tâche du tenant courant, après nettoyage (null : inconnue, expirée ou identifiant mal formé) */
    public function find(string $id): ?AiJob
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $this->cleanUp();

        return $this->jobs()->find($id);
    }

    public function start(AiJob $job): void
    {
        $job->start();
        $this->emProvider->getEntityManager()->flush();
    }

    public function succeed(AiJob $job, array $body): void
    {
        $job->succeed(LandingAiComposeRunner::encode($body));
        $this->emProvider->getEntityManager()->flush();
    }

    public function fail(AiJob $job, LandingAiException $e): void
    {
        $job->fail(LandingAiComposeRunner::encode(['status' => $e->getStatusCode()] + $e->toArray()));
        $this->emProvider->getEntityManager()->flush();
    }

    /** Supprime les résultats de plus d'une heure et fait échouer les tâches bloquées (crédits libérés) */
    public function cleanUp(): void
    {
        $now = new \DateTimeImmutable();
        $this->jobs()->deleteFinishedBefore($now->modify(sprintf('-%d seconds', self::RESULT_TTL_SECONDS)));

        $stale = $this->jobs()->findStale(
            $now->modify(sprintf('-%d seconds', self::PENDING_TIMEOUT_SECONDS)),
            $now->modify(sprintf('-%d seconds', self::RUNNING_TIMEOUT_SECONDS))
        );
        foreach ($stale as $job) {
            $usage = $this->emProvider->getEntityManager()->find(AiUsage::class, $job->getUsageId());
            if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($usage, null);
            }
            $this->fail($job, new LandingAiException(504, 'Délai dépassé', 'La demande n\'a pas pu être traitée à temps. Aucun crédit n\'a été consommé.'));
        }
    }

    private function jobs(): AiJobRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiJob::class);
    }
}
