<?php

namespace App\Repository;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\PosReceipt;
use Doctrine\ORM\EntityRepository;

/** Numéros de tickets et d'avoirs ; obtenu par l'EntityManager du tenant */
class PosReceiptRepository extends EntityRepository
{
    /** Numéro suivant de la suite (poste, genre) : à appeler sous verrou de la ligne du poste */
    public function nextNumber(CashRegister $register, string $kind): int
    {
        return 1 + (int) $this->createQueryBuilder('r')->select('MAX(r.number)')
            ->andWhere('r.cashRegister = :register')->andWhere('r.kind = :kind')
            ->setParameter('register', $register)->setParameter('kind', $kind)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array{first: ?int, last: ?int, count: int} */
    public function rangeForSession(Caisse $session, string $kind): array
    {
        $row = $this->createQueryBuilder('r')->select('MIN(r.number) AS first, MAX(r.number) AS last, COUNT(r.id) AS count')
            ->andWhere('r.session = :session')->andWhere('r.kind = :kind')
            ->setParameter('session', $session)->setParameter('kind', $kind)
            ->getQuery()->getSingleResult();

        return ['first' => $row['first'] !== null ? (int) $row['first'] : null, 'last' => $row['last'] !== null ? (int) $row['last'] : null, 'count' => (int) $row['count']];
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, array<string, PosReceipt>> commande → genre → numéro
     */
    public function forOrders(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }
        $map = [];
        foreach ($this->createQueryBuilder('r')->andWhere('r.order IN (:ids)')->setParameter('ids', $orderIds)->getQuery()->getResult() as $receipt) {
            $map[$receipt->getOrder()->getId()][$receipt->getKind()] = $receipt;
        }

        return $map;
    }
}
