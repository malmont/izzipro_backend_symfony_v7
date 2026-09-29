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
 * POST /api/landingpage-ai/compose (modes edit, create et page ; images acceptées dans tous les modes) : contrôles,
 * limite par minute, réservation des crédits, appel à l'IA,
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
        $defaultDataType = $dto->dataType !== null ? (string) $dto->dataType : null;
        if ($dto->mode === 'create' && $defaultDataType !== null) {
            if (!$this->data->familyUsesData((string) $dto->componentKey)) {
                throw LandingAiException::badRequest('Cette famille n\'utilise pas de donnée : dataType doit être absent ou null.', [['path' => 'dataType', 'message' => 'null attendu pour cette famille']]);
            }
            if (!in_array($defaultDataType, $this->data->availableIds((string) $dto->componentKey), true)) {
                throw LandingAiException::badRequest('Donnée introuvable sur ce site.', [['path' => 'dataType', 'message' => 'identifiant absent des données du site']]);
            }
        }

        $limit = $this->landingAiTenantLimiter->create('tenant:' . ($this->tenantProvider->getTenantCode() ?? 'default'))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new LandingAiException(429, 'Trop de demandes', sprintf('Limite de demandes à l\'assistant atteinte. Réessayez dans %d seconde(s).', $retryAfter), [], ['Retry-After' => (string) $retryAfter]);
        }

        $usage = $this->quota->reserve($dto->mode, $dto->componentKey ?? '', LandingAiQuotaService::cost($dto->mode, $dto->images !== []), $userIdentifier, $dto->prompt);

        try {
            $result = match ($dto->mode) {
                'create' => $this->composer->create((string) $dto->componentKey, $dto->prompt, $dto->locale, $dto->media, $defaultDataType, $dto->images),
                'page' => $this->composer->page($dto->prompt, $dto->locale, $dto->media, $dto->images, $dto->componentKey),
                default => $this->composer->edit((string) $dto->componentKey, $dto->composition, $dto->prompt, $dto->locale, $dto->media, $dto->images),
            };
        } catch (LandingAiException $e) {
            $this->quota->release($usage, $e->getStats());
            throw $e;
        } catch (\Throwable $e) {
            $this->quota->release($usage, null);
            throw $e;
        }

        $this->quota->complete($usage, $result->stats);

        $response = match ($dto->mode) {
            'page' => ['mode' => 'page', 'sections' => $result->sections],
            'create' => ['mode' => 'create', 'composition' => $result->composition, 'dataType' => $result->dataType],
            default => ['mode' => 'edit', 'composition' => $result->composition],
        };

        return $response + [
            'summary' => $result->summary,
            'warnings' => $result->warnings,
            'credits' => $this->quota->credits(),
            'usage' => $result->stats->toArray(),
        ];
    }
}
