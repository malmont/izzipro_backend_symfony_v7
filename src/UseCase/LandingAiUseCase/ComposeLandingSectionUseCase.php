<?php

namespace App\UseCase\LandingAiUseCase;

use App\Dto\LandingAiComposeInputDto;
use App\Dto\LandingAiComposeOutputDto;
use App\Message\LandingAiJobMessage;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiComposeRunner;
use App\Services\LandingAiService\LandingAiComposer;
use App\Services\LandingAiService\LandingAiDataSources;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiJobService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantConnectionProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /api/landingpage-ai/compose : contrôles, limite par minute, réservation des crédits, puis
 * - edit, create : appel à l'IA et réponse 200 avec la proposition ;
 * - page ou requête avec images : tâche de fond (worker Messenger), réponse 202 { jobId, credits }, résultat à lire
 *   sur GET /api/landingpage-ai/jobs/{jobId}.
 * N'enregistre jamais les réglages du site : la proposition est appliquée par l'administrateur dans l'éditeur puis
 * enregistrée avec le PUT habituel.
 */
class ComposeLandingSectionUseCase
{
    public function __construct(
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiComposeRunner $runner,
        private readonly LandingAiComposer $composer,
        private readonly LandingAiQuotaService $quota,
        private readonly LandingAiDataSources $data,
        private readonly LandingAiJobService $jobs,
        private readonly MessageBusInterface $bus,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly RateLimiterFactory $landingAiTenantLimiter
    ) {
    }

    /**
     * @throws LandingAiException
     */
    public function execute(LandingAiComposeInputDto $dto, ?string $userIdentifier): LandingAiComposeOutputDto
    {
        $errors = $dto->validate($this->catalogue->componentKeys());
        if ($errors) {
            throw LandingAiException::badRequest('Requête invalide : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }
        $defaultDataType = $dto->dataType !== null ? (string) $dto->dataType : null;
        if ($dto->mode === 'create' && $defaultDataType !== null) {
            if (!$this->data->familyUsesData((string) $dto->componentKey)) {
                throw LandingAiException::badRequest('Cette famille n\'utilise pas de donnée : dataType doit être absent ou null.', [['path' => 'dataType', 'message' => 'null attendu pour cette famille']]);
            }
            if (!in_array($defaultDataType, $this->data->availableIds((string) $dto->componentKey), true)) {
                throw LandingAiException::badRequest('Donnée introuvable sur ce site.', [['path' => 'dataType', 'message' => 'identifiant absent des données du site']]);
            }
        }

        if ($dto->mode === 'edit' && ($invalid = $this->composer->editErrors((string) $dto->componentKey, $dto->composition))) {
            throw new LandingAiException(422, 'Composition invalide', sprintf(
                'La composition actuelle de la section est invalide, l\'IA ne peut pas la retoucher : %s : %s. Aucun crédit n\'a été consommé.',
                $invalid[0]['path'], $invalid[0]['message']
            ), array_slice($invalid, 0, 40));
        }

        // Une seule tâche de fond à la fois par utilisateur : un double clic ne paie pas deux fois
        if ($dto->isAsync() && ($active = $this->jobs->activeFor($userIdentifier))) {
            throw new LandingAiException(409, 'Tâche en cours', 'Une demande est déjà en cours de traitement : attendez son résultat. Aucun crédit n\'a été consommé.',
                [], ['Location' => '/api/landingpage-ai/jobs/' . $active->getId()], null, ['jobId' => $active->getId(), 'status' => $active->getStatus()]);
        }

        $limit = $this->landingAiTenantLimiter->create('tenant:' . ($this->tenantProvider->getTenantCode() ?? 'default'))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new LandingAiException(429, 'Trop de demandes', sprintf('Limite de demandes à l\'assistant atteinte. Réessayez dans %d seconde(s).', $retryAfter), [], ['Retry-After' => (string) $retryAfter]);
        }

        $cost = LandingAiQuotaService::cost($dto->mode, $dto->images !== []);

        if ($dto->isAsync()) {
            $usage = $this->quota->reserve($dto->mode, $dto->componentKey ?? '', $cost, $userIdentifier, $dto->prompt, LandingAiQuotaService::JOB_RESERVATION_TTL_SECONDS);
            $job = null;
            try {
                $job = $this->jobs->create($usage, $userIdentifier, $dto->toJson());
                $this->bus->dispatch(new LandingAiJobMessage($job->getId(), (string) $this->tenantProvider->getTenantCode()));
            } catch (\Throwable $e) {
                $this->quota->release($usage, null);
                if ($job !== null) {
                    $this->jobs->fail($job, new LandingAiException(502, 'Service IA indisponible', 'La demande n\'a pas pu être mise en file. Aucun crédit n\'a été consommé.'));
                }
                throw $e;
            }

            return new LandingAiComposeOutputDto(202, ['jobId' => $job->getId(), 'credits' => $this->quota->credits()], ['Location' => '/api/landingpage-ai/jobs/' . $job->getId()]);
        }

        $usage = $this->quota->reserve($dto->mode, $dto->componentKey ?? '', $cost, $userIdentifier, $dto->prompt);

        return new LandingAiComposeOutputDto(200, $this->runner->run($dto, $usage));
    }
}
