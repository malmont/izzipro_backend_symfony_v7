<?php

namespace App\MemoiresVivantes\UseCase\Quota;

use App\MemoiresVivantes\Dto\AiQuotaSettingsInputDto;
use App\MemoiresVivantes\Entity\AiQuotaSetting;
use App\MemoiresVivantes\Services\AiQuotaService;

/** PUT /api/memoires/admin/ai-quotas et formulaire EasyAdmin : validation puis enregistrement des réglages du site */
class UpdateAiQuotaSettingsUseCase
{
    public function __construct(private readonly AiQuotaService $quotas)
    {
    }

    /** @throws \InvalidArgumentException réglages invalides (message = première erreur ; détail dans $dto->errors) */
    public function execute(AiQuotaSettingsInputDto $dto): AiQuotaSetting
    {
        if ($dto->errors !== []) {
            throw new \InvalidArgumentException($dto->errors[0]['path'] . ' : ' . $dto->errors[0]['message']);
        }

        return $this->quotas->save($dto->applyTo($this->quotas->settings()));
    }
}
