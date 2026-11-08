<?php

namespace App\Repository;

use App\Entity\SiteCharter;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<SiteCharter> Charte et brief du site : une ligne par site (08/11/2026). */
class SiteCharterRepository extends EntityRepository
{
    public function current(): ?SiteCharter
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
