<?php

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<ProductVariant>
 */
class ProductVariantRepository extends EntityRepository
{
 /**
     * Trouve une variante existante avec la même combinaison de produit, couleur, taille et valeurs d'options.
     */
    public function findExistingVariant(ProductVariant $variantToCompare): ?ProductVariant
    {
        // 1. On récupère les IDs des valeurs d'option de la variante à tester
        $optionValueIds = $variantToCompare->getOptionValues()->map(fn($ov) => $ov->getId())->toArray();
        $optionCount = count($optionValueIds);

        // 2. On construit la requête
        $qb = $this->createQueryBuilder('pv');
        
        $qb->where('pv.product = :product')
           ->andWhere('pv.color = :color')
           ->andWhere('pv.size = :size')
           ->setParameter('product', $variantToCompare->getProduct())
           ->setParameter('color', $variantToCompare->getColor())
           ->setParameter('size', $variantToCompare->getSize());

        // 3. On gère la relation ManyToMany
        if ($optionCount > 0) {
            $qb->join('pv.optionValues', 'ov')
               ->andWhere($qb->expr()->in('ov.id', ':optionValueIds'))
               ->groupBy('pv.id')
               // La clause HAVING garantit qu'on a le bon nombre de correspondances exactes
               ->having('COUNT(DISTINCT ov.id) = :optionCount')
               ->setParameter('optionValueIds', $optionValueIds)
               ->setParameter('optionCount', $optionCount);
        } else {
            // S'il n'y a pas d'options, on cherche une variante qui n'en a pas non plus
            $qb->leftJoin('pv.optionValues', 'ov_null')
               ->andWhere('ov_null.id IS NULL');
        }

        // 4. On s'assure de ne pas trouver la variante elle-même si on est en mode édition
        if ($variantToCompare->getId()) {
            $qb->andWhere('pv.id != :currentId')
               ->setParameter('currentId', $variantToCompare->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}