<?php

namespace App\UseCase\LandingAiUseCase;

use App\Dto\LandingAiComposeInputDto;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiComposer;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantConnectionProvider;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /api/landingpage-ai/compose : contrôles, limite par minute, réservation des crédits, appel à l'IA,
 * puis consommation (succès) ou libération (échec). N'enregistre jamais les réglages du site : la proposition
 * est appliquée par l'administrateur dans l'éditeur puis enregistrée avec le PUT habituel.
 */
class ComposeLandingSectionUseCase
{
    public function __construct(
        private readonly LandingAiCatalogue $catalogue,
        private readonly LandingAiComposer $composer,
        private readonly LandingAiQuotaService $quota,
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
        if ($dto->mode !== 'edit') {
            throw LandingAiException::badRequest(sprintf('Le mode « %s » n\'est pas encore disponible (étape 1 : retouche uniquement).', $dto->mode));
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
            $result = $this->composer->edit((string) $dto->componentKey, $dto->composition, $dto->prompt, $dto->locale, $dto->media);
        } catch (LandingAiException $e) {
            $this->quota->release($usage, $e->getStats());
            throw $e;
        } catch (\Throwable $e) {
            $this->quota->release($usage, null);
            throw $e;
        }

        $this->quota->complete($usage, $result->stats);

        return [
            'mode' => 'edit',
            'composition' => $result->composition,
            'summary' => $result->summary,
            'warnings' => $result->warnings,
            'credits' => $this->quota->credits(),
            'usage' => $result->stats->toArray(),
        ];
    }
}
