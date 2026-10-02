<?php

namespace App\UseCase\LandingAiUseCase;

use App\Dto\LandingAiVideoPromptInputDto;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiVideoPromptWriter;
use App\Services\TenantConnectionProvider;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /api/landingpage-ai/video-prompt : contrôles, limite par minute (commune à l'assistant), réservation d'un
 * crédit, appel à l'IA (modèle de la retouche), réponse synchrone { prompt, promptMobile?, steps, notes, credits }.
 * Un échec libère le crédit.
 */
class WriteLandingVideoPromptUseCase
{
    public const MODE = 'video';

    public function __construct(
        private readonly LandingAiVideoPromptWriter $writer,
        private readonly LandingAiQuotaService $quota,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly RateLimiterFactory $landingAiTenantLimiter
    ) {
    }

    /**
     * @throws LandingAiException
     */
    public function execute(LandingAiVideoPromptInputDto $dto, ?string $userIdentifier): array
    {
        if ($errors = $dto->validate()) {
            throw LandingAiException::badRequest('Requête invalide : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }

        $limit = $this->landingAiTenantLimiter->create('tenant:' . ($this->tenantProvider->getTenantCode() ?? 'default'))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new LandingAiException(429, 'Trop de demandes', sprintf('Limite de demandes à l\'assistant atteinte. Réessayez dans %d seconde(s).', $retryAfter), [], ['Retry-After' => (string) $retryAfter]);
        }

        $usage = $this->quota->reserve(self::MODE, 'Video', LandingAiQuotaService::VIDEO_PROMPT_COST, $userIdentifier, $dto->prompt);
        try {
            $result = $this->writer->write($dto);
        } catch (LandingAiException $e) {
            $this->quota->release($usage, $e->getStats());
            throw $e;
        } catch (\Throwable $e) {
            $this->quota->release($usage, null);
            throw $e;
        }
        $this->quota->complete($usage, $result->stats);

        return ['prompt' => $result->prompt]
            + ($result->promptMobile !== null ? ['promptMobile' => $result->promptMobile] : [])
            + ['steps' => $result->steps, 'notes' => $result->notes, 'credits' => $this->quota->credits(), 'usage' => $result->stats->toArray()];
    }
}
