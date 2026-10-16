<?php

namespace App\MemoiresVivantes\Repository;

use App\MemoiresVivantes\Entity\PricingSetting;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PricingSetting> */
class PricingSettingRepository extends EntityRepository
{
    public function current(): ?PricingSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
