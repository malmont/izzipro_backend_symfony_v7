<?php

namespace App\UseCase\LandingAiUseCase;

use App\Dto\LandingAiComposeInputDto;
use App\Entity\AiJob;
use App\Entity\AiUsage;
use App\Services\LandingAiService\LandingAiComposeRunner;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiJobService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiSiteOrchestrator;
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
        private readonly LoggerInterface $logger,
        private readonly LandingAiSiteOrchestrator $sites
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
        $dto = LandingAiComposeInputDto::fromStoredJob((string) $job->getInput());
        $this->jobs->start($job);

        try {
            if ($usage === null || $usage->getStatus() !== AiUsage::STATUS_RESERVED) {
                throw new LandingAiException(504, 'Délai dépassé', 'La réservation des crédits a expiré avant le traitement. Aucun crédit n\'a été consommé.');
            }
            $body = $this->runner->run($dto, $usage);
            $this->jobs->succeed($job, $body);
            if ($dto->mode === 'site' && ($body['needsAnswers'] ?? false) === true) {
                // questions sur l'accueil : les pages attendent la nouvelle demande, crédits libérés
                $this->sites->cancelChildrenForQuestions($dto);
            } elseif ($dto->mode === 'site') {
                // accueil composé : les pages système partent au worker avec sa charte et son style
                $this->sites->launchChildren($dto, $body);
            }
        } catch (LandingAiException $e) {
            $this->jobs->fail($job, $e);
            $this->failSite($dto, $e);
        } catch (\Throwable $e) {
            $this->logger->error('Assistant IA : tâche en échec', ['job' => $jobId, 'message' => $e->getMessage()]);
            if ($usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($usage, null);
            }
            $internal = new LandingAiException(500, 'Erreur interne', 'La demande n\'a pas pu être traitée. Aucun crédit n\'a été consommé.');
            $this->jobs->fail($job, $internal);
            $this->failSite($dto, $internal);
        }

        // Nettoyage du site : résultats de plus d'une heure, tâches bloquées
        $this->jobs->cleanUp();
    }

    /** Accueil d'un site en échec : ses pages système n'ont plus de charte à suivre, elles échouent aussi */
    private function failSite(LandingAiComposeInputDto $dto, LandingAiException $e): void
    {
        if ($dto->mode === 'site' && $dto->children !== []) {
            $this->sites->failChildren($dto, $e);
        }
    }
}
