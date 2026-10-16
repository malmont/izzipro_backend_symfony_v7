<?php

namespace App\MemoiresVivantes\Dto;

use App\MemoiresVivantes\Entity\AiQuotaSetting;

/** Réglages des quotas IA tels que rendus par GET/PUT /api/memoires/admin/ai-quotas (0 = illimité) */
class AiQuotaSettingsOutputDto
{
    public static function toArray(AiQuotaSetting $settings): array
    {
        return [
            'transcriptionsPerDay' => $settings->getTranscriptionsPerDay(),
            'improvementsPerDay' => $settings->getImprovementsPerDay(),
            'regenerationsPerDay' => $settings->getRegenerationsPerDay(),
            'maxAudioSeconds' => $settings->getMaxAudioSeconds(),
            'applyToWebBooks' => $settings->isApplyToWebBooks(),
            'timezone' => $settings->getTimezone(),
            'isDefault' => $settings->getId() === null,
            'updatedAt' => $settings->getId() === null ? null : $settings->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
