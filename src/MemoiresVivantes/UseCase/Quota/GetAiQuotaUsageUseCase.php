<?php

namespace App\MemoiresVivantes\UseCase\Quota;

use App\MemoiresVivantes\Services\AiQuotaService;

/** GET /api/memoires/admin/ai-quotas/usage?date=AAAA-MM-JJ : consommation du jour par utilisateur, avec les limites */
class GetAiQuotaUsageUseCase
{
    public function __construct(private readonly AiQuotaService $quotas)
    {
    }

    /** @throws \InvalidArgumentException date mal formée */
    public function execute(?string $date): array
    {
        $settings = $this->quotas->settings();

        return $this->quotas->usageByUser($date) + ['limits' => [
            'transcriptionsPerDay' => $settings->getTranscriptionsPerDay(), 'improvementsPerDay' => $settings->getImprovementsPerDay(),
        ]];
    }
}
