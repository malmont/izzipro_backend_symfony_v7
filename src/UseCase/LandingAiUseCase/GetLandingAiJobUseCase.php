<?php

namespace App\UseCase\LandingAiUseCase;

use App\Entity\AiJob;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiJobService;

/**
 * GET /api/landingpage-ai/jobs/{jobId} : état d'une tâche de fond du tenant courant. Corps renvoyé en JSON brut :
 * le résultat garde ses {} et ses nombres décimaux.
 */
class GetLandingAiJobUseCase
{
    public function __construct(private readonly LandingAiJobService $jobs)
    {
    }

    /**
     * @return string JSON { jobId, status, result? (réponse du mode synchrone), error? { status, error, message, errors? } }
     * @throws LandingAiException 404 inconnue, expirée ou d'un autre tenant
     */
    public function execute(string $jobId): string
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            throw new LandingAiException(404, 'Tâche introuvable', 'Cette demande est inconnue ou son résultat a expiré (conservé 1 heure).');
        }

        $json = sprintf('{"jobId":%s,"status":%s', json_encode($job->getId()), json_encode($job->getStatus()));
        if ($job->getStatus() === AiJob::STATUS_DONE) {
            $json .= ',"result":' . $job->getResult();
        } elseif ($job->getStatus() === AiJob::STATUS_FAILED) {
            $json .= ',"error":' . $job->getError();
        }

        return $json . '}';
    }
}
