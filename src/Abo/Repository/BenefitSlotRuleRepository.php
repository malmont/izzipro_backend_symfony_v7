<?php

namespace App\Abo\Repository;

use App\Abo\Entity\BenefitSlotRule;
use Doctrine\ORM\EntityRepository;

/** Règles de créneaux (lot ABO-2) ; obtenu par TenantEntityManagerProvider */
class BenefitSlotRuleRepository extends EntityRepository
{
    /** @return list<BenefitSlotRule> */
    public function active(): array
    {
        return $this->findBy(['active' => true], ['id' => 'ASC']);
    }
}
