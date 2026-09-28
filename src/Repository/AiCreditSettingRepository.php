<?php

namespace App\Repository;

use App\Entity\AiCreditSetting;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<AiCreditSetting>
 */
class AiCreditSettingRepository extends EntityRepository
{
    /** Crédits mensuels du tenant ; valeur par défaut tant qu'aucune ligne n'existe */
    public function monthlyCredits(): int
    {
        $setting = $this->findOneBy([], ['id' => 'ASC']);

        return $setting?->getMonthlyCredits() ?? AiCreditSetting::DEFAULT_MONTHLY_CREDITS;
    }
}
