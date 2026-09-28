<?php

namespace App\UseCase\LandingAiUseCase;

use App\Services\LandingAiService\LandingAiQuotaService;

/**
 * GET /api/landingpage-ai/usage : crédits du mois et 50 dernières demandes du tenant.
 */
class GetLandingAiUsageUseCase
{
    public const HISTORY_LIMIT = 50;

    public function __construct(private readonly LandingAiQuotaService $quota)
    {
    }

    public function execute(): array
    {
        return [
            'credits' => $this->quota->credits(),
            'history' => $this->quota->history(self::HISTORY_LIMIT),
        ];
    }
}
