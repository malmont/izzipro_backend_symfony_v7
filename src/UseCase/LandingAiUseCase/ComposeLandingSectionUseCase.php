<?php

namespace App\UseCase\LandingAiUseCase;

use App\Dto\LandingAiComposeInputDto;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiComposer;
use App\Services\LandingAiService\LandingAiDataSources;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantConnectionProvider;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /api/landingpage-ai/compose (modes edit et create) : contrôles, limite par minute, réservation des crédits, appel à l'IA,
 * puis consommation (succès) ou libération (échec). N'enregistre jamais les réglages du site : la proposition
 * est appliquée par l'administrateur dans l'éditeur puis enregistrée avec le PUT habituel.
 */
class ComposeLandingSectionUseCase
{
    public function __construct(
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiComposer $composer,
        private readonly LandingAiQuotaService $quota,
        private readonly LandingAiDataSources $data,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly RateLimiterFactory $landingAiTenantLimiter
    ) {
    }

    /**
     * @throws LandingAiException
     */
    public function execute(LandingAiComposeInputDto $dto, ?string $userIdentifier): array
    {
        $errors = $dto->validate($this->catalogue->componentKeys());
        if ($errors) {
            throw LandingAiException::badRequest('Requête invalide : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }
        if ($dto->mode === 'page') {
            throw LandingAiException::badRequest('Le mode « page » n\'est pas encore disponible (retouche et création uniquement).');
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
        if ($dto->images) {
            throw LandingAiException::badRequest('Les images (captures, charte) ne sont pas encore prises en charge.');
        }

        $limit = $this->landingAiTenantLimiter->create('tenant:' . ($this->tenantProvider->getTenantCode() ?? 'default'))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new LandingAiException(429, 'Trop de demandes', sprintf('Limite de demandes à l\'assistant atteinte. Réessayez dans %d seconde(s).', $retryAfter), [], ['Retry-After' => (string) $retryAfter]);
        }

        $usage = $this->quota->reserve($dto->mode, (string) $dto->componentKey, LandingAiQuotaService::cost($dto->mode), $userIdentifier, $dto->prompt);

        try {
            $result = $dto->mode === 'create'
                ? $this->composer->create((string) $dto->componentKey, $dto->prompt, $dto->locale, $dto->media, $defaultDataType)
                : $this->composer->edit((string) $dto->componentKey, $dto->composition, $dto->prompt, $dto->locale, $dto->media);
        } catch (LandingAiException $e) {
            $this->quota->release($usage, $e->getStats());
            throw $e;
        } catch (\Throwable $e) {
            $this->quota->release($usage, null);
            throw $e;
        }

        $this->quota->complete($usage, $result->stats);

        $response = ['mode' => $dto->mode, 'composition' => $result->composition];
        if ($dto->mode === 'create') {
            $response['dataType'] = $result->dataType;
        }

        return $response + [
            'summary' => $result->summary,
            'warnings' => $result->warnings,
            'credits' => $this->quota->credits(),
            'usage' => $result->stats->toArray(),
        ];
    }
}
