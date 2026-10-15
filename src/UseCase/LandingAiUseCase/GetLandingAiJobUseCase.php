<?php

namespace App\UseCase\LandingAiUseCase;

use App\Entity\AiJob;
use App\Services\LandingAiService\LandingAiComposeRunner;
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
            $json .= ',"result":' . $this->withPageStatuses((string) $job->getResult());
        } elseif ($job->getStatus() === AiJob::STATUS_FAILED) {
            $json .= ',"error":' . $job->getError();
        }

        return $json . '}';
    }

    /**
     * Site complet : l'état courant de chaque page système (tâche fille) est ajouté au résultat de l'accueil
     * (pages[page] : jobId, status, error?). Le résultat de la page se lit sur sa propre tâche.
     */
    private function withPageStatuses(string $result): string
    {
        if (!str_contains($result, '"pages"')) {
            return $result;
        }
        $body = json_decode($result, false);
        if (!is_object($body) || ($body->mode ?? null) !== 'site' || !is_object($body->pages ?? null)) {
            return $result;
        }
        foreach (get_object_vars($body->pages) as $page => $entry) {
            $child = is_object($entry) && is_string($entry->jobId ?? null) ? $this->jobs->find($entry->jobId) : null;
            $entry->status = $child?->getStatus() ?? 'expired';
            if ($child?->getStatus() === AiJob::STATUS_FAILED) {
                $entry->error = json_decode((string) $child->getError(), false);
            }
        }

        return LandingAiComposeRunner::encode((array) $body);
    }
}
