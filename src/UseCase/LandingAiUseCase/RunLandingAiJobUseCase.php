<?php

namespace App\UseCase\LandingAiUseCase;

use App\Dto\LandingAiComposeInputDto;
use App\Entity\AiJob;
use App\Entity\AiUsage;
use App\Services\LandingAiService\LandingAiComposeRunner;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiJobService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Traitement d'une tâche de fond par le worker (tenant déjà sélectionné) : seulement si elle est encore en attente,
 * pour qu'une livraison répétée du message ne relance pas l'IA. Les erreurs sont écrites dans la tâche, jamais
 * relancées (un nouvel essai du message consommerait des appels à l'IA).
 */
class RunLandingAiJobUseCase
{
    public function __construct(
        private readonly LandingAiJobService $jobs,
        private readonly LandingAiComposeRunner $runner,
        private readonly LandingAiQuotaService $quota,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(string $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->getStatus() !== AiJob::STATUS_PENDING) {
            $this->logger->info('Assistant IA : tâche ignorée (inconnue ou déjà traitée)', ['job' => $jobId]);
            return;
        }
        $usage = $this->emProvider->getEntityManager()->find(AiUsage::class, $job->getUsageId());
        $dto = LandingAiComposeInputDto::fromRequestBody(json_decode((string) $job->getInput(), false));
        $this->jobs->start($job);

        try {
            if ($usage === null || $usage->getStatus() !== AiUsage::STATUS_RESERVED) {
                throw new LandingAiException(504, 'Délai dépassé', 'La réservation des crédits a expiré avant le traitement. Aucun crédit n\'a été consommé.');
            }
            $this->jobs->succeed($job, $this->runner->run($dto, $usage));
        } catch (LandingAiException $e) {
            $this->jobs->fail($job, $e);
        } catch (\Throwable $e) {
            $this->logger->error('Assistant IA : tâche en échec', ['job' => $jobId, 'message' => $e->getMessage()]);
            if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($usage, null);
            }
            $this->jobs->fail($job, new LandingAiException(500, 'Erreur interne', 'La demande n\'a pas pu être traitée. Aucun crédit n\'a été consommé.'));
        }

        // Nettoyage du site : résultats de plus d'une heure, tâches bloquées
        $this->jobs->cleanUp();
    }
}
