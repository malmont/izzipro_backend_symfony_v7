<?php

namespace App\Repository;

use App\Entity\ReviewSetting;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ReviewSetting> */
class ReviewSettingRepository extends EntityRepository
{
    /** La ligne de réglages du site (la première), ou null */
    public function current(): ?ReviewSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
