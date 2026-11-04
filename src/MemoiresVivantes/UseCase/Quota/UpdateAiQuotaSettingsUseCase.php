<?php

namespace App\MemoiresVivantes\UseCase\Quota;

use App\MemoiresVivantes\Dto\AiQuotaSettingsInputDto;
use App\MemoiresVivantes\Entity\AiQuotaSetting;
use App\MemoiresVivantes\Services\AiQuotaService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PUT /api/memoires/admin/ai-quotas et formulaire EasyAdmin : validation puis enregistrement des réglages du site.
 * Les plafonds (AiQuotaSettingsInputDto::CEILING_FIELDS) sont réservés au super administrateur : un administrateur de site ne
 * change que le fuseau horaire ; un plafond renvoyé inchangé est ignoré, un plafond différent → QuotaCeilingForbiddenException
 * (403 SUPER_ADMIN_REQUIRED), rien n'est écrit (verrou API du 04/11/2026, même règle que l'écran EasyAdmin du 03/11/2026).
 */
class UpdateAiQuotaSettingsUseCase
{
    public function __construct(private readonly AiQuotaService $quotas, private readonly Security $security)
    {
    }

    /**
     * @throws \InvalidArgumentException réglages invalides (message = première erreur ; détail dans $dto->errors)
     * @throws QuotaCeilingForbiddenException plafond modifié par un administrateur de site
     */
    public function execute(AiQuotaSettingsInputDto $dto): AiQuotaSetting
    {
        if ($dto->errors !== []) {
            throw new \InvalidArgumentException($dto->errors[0]['path'] . ' : ' . $dto->errors[0]['message']);
        }
        $settings = $this->quotas->settings();
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $refused = [];
            foreach (AiQuotaSettingsInputDto::CEILING_FIELDS as $field => $getter) {
                if ($dto->{$field} !== null && $dto->{$field} !== $settings->{$getter}()) {
                    $refused[] = $field;
                }
                $dto->{$field} = null;
            }
            if ($refused !== []) {
                throw new QuotaCeilingForbiddenException($refused);
            }
        }

        return $this->quotas->save($dto->applyTo($settings));
    }
}
