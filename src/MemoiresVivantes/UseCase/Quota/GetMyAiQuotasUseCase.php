<?php

namespace App\MemoiresVivantes\UseCase\Quota;

use App\Entity\User;
use App\MemoiresVivantes\Services\AiQuotaService;

/** GET /api/memoires/mobile/quotas : état des quotas IA de l'utilisateur connecté */
class GetMyAiQuotasUseCase
{
    public function __construct(private readonly AiQuotaService $quotas)
    {
    }

    public function execute(User $user): array
    {
        $usage = $this->quotas->usageFor($user);

        return ['transcription' => $usage['transcription'], 'improvement' => $usage['improvement'], 'maxAudioSeconds' => $usage['maxAudioSeconds']];
    }
}
