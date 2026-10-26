<?php

namespace App\Repository;

use App\Entity\CashRegister;
use App\Entity\PosFavorite;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<PosFavorite> */
class PosFavoriteRepository extends EntityRepository
{
    /** @return list<PosFavorite> épinglés d'une portée (poste, ou site si null), dans l'ordre choisi */
    public function forScope(?CashRegister $register): array
    {
        $qb = $this->createQueryBuilder('f')->addSelect('p', 'v')->join('f.product', 'p')->leftJoin('f.productVariant', 'v')->orderBy('f.position', 'ASC')->addOrderBy('f.id', 'ASC');
        $register === null ? $qb->where('f.cashRegister IS NULL') : $qb->where('f.cashRegister = :r')->setParameter('r', $register);

        return $qb->getQuery()->getResult();
    }

    /**
     * Plus vendus en caisse : ventes au comptoir (source 2, type vente, non annulées) de la période [from, to[ (UTC), quantités nettes des
     * retours faits sur ces ventes ; par produit, du plus vendu au moins vendu. Une requête d'agrégat.
     *
     * @return list<array{productId: int, quantity: int}>
     */
    public function bestSellers(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            WITH sold AS (
                SELECT i.id, i.product_variant_id, i.quantity
                FROM order_items i JOIN "order" o ON o.id = i.order_associated_id
                WHERE o.order_source_id = 2 AND o.order_type_id = 1 AND (o.status_id IS NULL OR o.status_id <> 7)
                  AND o.order_date >= :from AND o.order_date < :to AND i.product_variant_id IS NOT NULL
            ), returned AS (
                SELECT r.returned_from_item_id AS item_id, SUM(r.quantity) AS quantity
                FROM order_items r JOIN "order" ro ON ro.id = r.order_associated_id
                WHERE ro.order_type_id = 2 AND r.returned_from_item_id IN (SELECT id FROM sold)
                GROUP BY r.returned_from_item_id
            )
            SELECT v.product_id, SUM(s.quantity - COALESCE(rt.quantity, 0)) AS net
            FROM sold s JOIN product_variant v ON v.id = s.product_variant_id LEFT JOIN returned rt ON rt.item_id = s.id
            GROUP BY v.product_id
            HAVING SUM(s.quantity - COALESCE(rt.quantity, 0)) > 0
            ORDER BY net DESC, v.product_id ASC
            LIMIT :limit
            SQL, ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'), 'limit' => $limit]);

        return array_map(fn (array $r) => ['productId' => (int) $r['product_id'], 'quantity' => (int) $r['net']], $rows);
    }
}
