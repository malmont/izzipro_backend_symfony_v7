<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\Product;
use App\Entity\ProductVariant;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<CommandeLine> */
class CommandeLineRepository extends EntityRepository
{
    /** Ligne de cet article (et de cette variante, ou sans variante) dans la commande */
    public function findOne(Commande $commande, Product $product, ?ProductVariant $variant): ?CommandeLine
    {
        $qb = $this->createQueryBuilder('l')->where('l.commande = :c')->andWhere('l.product = :p')->setParameter('c', $commande)->setParameter('p', $product)->setMaxResults(1);
        $variant === null ? $qb->andWhere('l.variant IS NULL') : $qb->andWhere('l.variant = :v')->setParameter('v', $variant);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** Dernière ligne saisie de l'article (raccourcis du produit), toutes commandes confondues */
    public function latestForProduct(Product $product): ?CommandeLine
    {
        return $this->findOneBy(['product' => $product], ['attachedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<CommandeLine> */
    public function forCommande(Commande $commande): array
    {
        return $this->findBy(['commande' => $commande], ['id' => 'ASC']);
    }

    /** @return list<CommandeLine> */
    public function forCommandeAndProduct(Commande $commande, Product $product): array
    {
        return $this->findBy(['commande' => $commande, 'product' => $product], ['id' => 'ASC']);
    }

    /** @return array{0: list<CommandeLine>, 1: int} lignes dont il reste à recevoir, triées par date de commande, et leur nombre */
    public function toReceive(int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('l')->join('l.commande', 'c')->where('l.quantityReceived < l.quantityOrdered');
        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();

        return [$qb->orderBy('c.date', 'ASC')->addOrderBy('l.id', 'ASC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult(), $total];
    }

    public function hasAny(Product $product): bool
    {
        return $this->count(['product' => $product]) > 0;
    }

    /** @return list<int> identifiants des produits ayant des lignes dans la commande */
    public function productIdsOf(Commande $commande): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT DISTINCT product_id FROM commande_line WHERE commande_id = ? ORDER BY product_id', [$commande->getId()]));
    }
}
