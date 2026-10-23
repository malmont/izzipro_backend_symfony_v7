<?php

namespace App\Repository;

use App\Entity\PosGiftCard;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosGiftCard> */
class PosGiftCardRepository extends EntityRepository
{
    public function findByHash(string $hash): ?PosGiftCard
    {
        return $this->findOneBy(['codeHash' => $hash]);
    }

    /** Solde en cours de toutes les cartes et avoirs actifs du site (passif, cents) */
    public function outstanding(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne("SELECT COALESCE(SUM(balance), 0) FROM pos_gift_card WHERE status = 'active'");
    }
}
