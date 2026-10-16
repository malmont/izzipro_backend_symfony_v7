<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\AiQuotaSetting;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<AiQuotaSetting> */
class AiQuotaSettingRepository extends EntityRepository
{
    /** La ligne du site, ou null (valeurs par défaut) */
    public function current(): ?AiQuotaSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
