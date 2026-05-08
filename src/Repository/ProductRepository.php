<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\ORM\EntityRepository;


class ProductRepository extends EntityRepository
{
    public function save(Product $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Product $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findWithSearch($search, string $locale = 'fr')
    {
        $query = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.saleUnit', 'su')
            ->leftJoin('p.style', 's')
            ->leftJoin('p.bookingConfiguration', 'b')
            ->leftJoin('p.pictures', 'pict')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('cat.translations', 'ct', 'WITH', 'ct.language = :locale')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('v.color', 'vc')
            ->leftJoin('vc.translations', 'vct', 'WITH', 'vct.language = :locale')
            ->leftJoin('v.size', 'vs')
            ->leftJoin('vs.translations', 'vst', 'WITH', 'vst.language = :locale')
            ->leftJoin('v.optionValues', 'vov')
            ->leftJoin('vov.translations', 'vovt', 'WITH', 'vovt.language = :locale')
            ->leftJoin('vov.productOption', 'po')
            ->leftJoin('po.translations', 'pot', 'WITH', 'pot.language = :locale')
            ->addSelect('t', 'su', 's', 'b', 'pict', 'cat', 'ct', 'v', 'vc', 'vct', 'vs', 'vst', 'vov', 'vovt', 'po', 'pot')
            ->setParameter('locale', $locale);
            
        if ($search->getMinPrice()) {
            $query->andWhere('p.price > :minPrice')
                  ->setParameter('minPrice', $search->getMinPrice() * 100);
        }
        if ($search->getMaxPrice()) {
            $query->andWhere('p.price < :maxPrice')
                  ->setParameter('maxPrice', $search->getMaxPrice() * 100);
        }
        if ($search->getCategories()) {
            $query->join('p.category', 'filter_cat')
                  ->andWhere('filter_cat.id IN (:categories)')
                  ->setParameter('categories', $search->getCategories());
        }
        if ($search->getTags()) {
            $query->andWhere('p.tags LIKE :val')
                  ->setParameter('val', "%" . $search->getTags() . "%");
        }

        return $query->getQuery()->getResult();
    }

    public function calculateCurrentStockValue(): float
    {
        $qb = $this->createQueryBuilder('p')
            ->select('SUM(p.purchasePrice * p.coefficientMultiplier * p.quantity) as stockValue')
            ->where('p.purchasePrice IS NOT NULL')
            ->andWhere('p.coefficientMultiplier IS NOT NULL');

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) $result;
    }
    public function findByCategoryAndFilters(
        string $locale,
        ?array $categoryIds,
        ?string $keyword,
        int $page,
        int $pageSize,
        ?string $barcode,
        ?bool $isWeb,
        ?bool $isPos
    ): array {
        $queryBuilder = $this->createQueryBuilder('p')
            // Jointures de base déjà présentes
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.saleUnit', 'su')
            ->leftJoin('p.style', 's')
            ->leftJoin('p.bookingConfiguration', 'b')
            // Nouvelles jointures pour optimiser le ProductOutputCategoryDto
            ->leftJoin('p.pictures', 'pict')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('cat.translations', 'ct', 'WITH', 'ct.language = :locale')
            ->leftJoin('cat.rentalPacks', 'rp')
            ->leftJoin('rp.translations', 'rpt', 'WITH', 'rpt.language = :locale')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('v.color', 'vc')
            ->leftJoin('vc.translations', 'vct', 'WITH', 'vct.language = :locale')
            ->leftJoin('v.size', 'vs')
            ->leftJoin('vs.translations', 'vst', 'WITH', 'vst.language = :locale')
            ->leftJoin('v.optionValues', 'vov')
            ->leftJoin('vov.translations', 'vovt', 'WITH', 'vovt.language = :locale')
            ->leftJoin('vov.productOption', 'po')
            ->leftJoin('po.translations', 'pot', 'WITH', 'pot.language = :locale')
            // Sélection massive pour hydrater les objets en une seule requête
            ->addSelect('t', 'su', 's', 'b', 'pict', 'cat', 'ct', 'rp', 'rpt', 'v', 'vc', 'vct', 'vs', 'vst', 'vov', 'vovt', 'po', 'pot')
            ->setParameter('locale', $locale);

        if ($categoryIds) {
            $queryBuilder->join('p.category', 'filter_cat')
                         ->andWhere('filter_cat.id IN (:categoryIds)')
                         ->setParameter('categoryIds', $categoryIds);
        }

        if ($keyword) {
            $queryBuilder->andWhere('(p.name LIKE :keyword OR p.description LIKE :keyword OR t.name LIKE :keyword OR t.description LIKE :keyword)')
                         ->setParameter('keyword', '%' . $keyword . '%');
        }

        if ($barcode) {
            $queryBuilder->andWhere('p.barcode = :barcode')
                         ->setParameter('barcode', $barcode);
        }
        
        if ($isWeb !== null) {
            $queryBuilder->andWhere('p.isWeb = :isWeb')
                         ->setParameter('isWeb', $isWeb);
        }

        if ($isPos !== null) {
            $queryBuilder->andWhere('p.isPos = :isPos')
                         ->setParameter('isPos', $isPos);
        }

        $queryBuilder->setFirstResult(($page - 1) * $pageSize)
                     ->setMaxResults($pageSize);

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($queryBuilder, true);
        
        $results = [];
        foreach ($paginator as $product) {
            $results[] = $product;
        }

        return $results;
    }
    public function findTranslatedByCriteria(string $locale, array $criteria): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.saleUnit', 'su')
            ->leftJoin('p.style', 's')
            ->leftJoin('p.bookingConfiguration', 'b')
            ->leftJoin('p.pictures', 'pict')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('cat.translations', 'ct', 'WITH', 'ct.language = :locale')
            ->leftJoin('cat.rentalPacks', 'rp')
            ->leftJoin('rp.translations', 'rpt', 'WITH', 'rpt.language = :locale')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('v.color', 'vc')
            ->leftJoin('vc.translations', 'vct', 'WITH', 'vct.language = :locale')
            ->leftJoin('v.size', 'vs')
            ->leftJoin('vs.translations', 'vst', 'WITH', 'vst.language = :locale')
            ->leftJoin('v.optionValues', 'vov')
            ->leftJoin('vov.translations', 'vovt', 'WITH', 'vovt.language = :locale')
            ->leftJoin('vov.productOption', 'po')
            ->leftJoin('po.translations', 'pot', 'WITH', 'pot.language = :locale')
            ->addSelect('t', 'su', 's', 'b', 'pict', 'cat', 'ct', 'rp', 'rpt', 'v', 'vc', 'vct', 'vs', 'vst', 'vov', 'vovt', 'po', 'pot')
            ->setParameter('locale', $locale);

        foreach ($criteria as $field => $value) {
            $queryBuilder->andWhere("p.{$field} = :{$field}")
                         ->setParameter($field, $value);
        }

        return $queryBuilder->getQuery()->getResult();
    }
    public function findByIdAndLocale(int $id, string $locale): ?Product
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.saleUnit', 'su')
            ->leftJoin('p.style', 's')
            ->leftJoin('p.bookingConfiguration', 'b')
            ->leftJoin('p.pictures', 'pict')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('cat.translations', 'ct', 'WITH', 'ct.language = :locale')
            ->leftJoin('cat.rentalPacks', 'rp')
            ->leftJoin('rp.translations', 'rpt', 'WITH', 'rpt.language = :locale')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('v.color', 'vc')
            ->leftJoin('vc.translations', 'vct', 'WITH', 'vct.language = :locale')
            ->leftJoin('v.size', 'vs')
            ->leftJoin('vs.translations', 'vst', 'WITH', 'vst.language = :locale')
            ->leftJoin('v.optionValues', 'vov')
            ->leftJoin('vov.translations', 'vovt', 'WITH', 'vovt.language = :locale')
            ->leftJoin('vov.productOption', 'po')
            ->leftJoin('po.translations', 'pot', 'WITH', 'pot.language = :locale')
            ->addSelect('t', 'su', 's', 'b', 'pict', 'cat', 'ct', 'rp', 'rpt', 'v', 'vc', 'vct', 'vs', 'vst', 'vov', 'vovt', 'po', 'pot')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findBySlugAndLocale(string $slug, string $locale): ?Product
    {
        $dql = "SELECT p, t, t_fr, pict, b, s, v, vct, vst, vc, vs, vov, povt, po, pot, cat, ct, rp, su
                FROM App\Entity\Product p
                LEFT JOIN p.translations t WITH t.locale = :locale
                LEFT JOIN p.translations t_fr WITH t_fr.locale = 'fr'
                LEFT JOIN p.translations all_t
                LEFT JOIN p.pictures pict
                LEFT JOIN p.bookingConfiguration b
                LEFT JOIN p.style s
                LEFT JOIN p.variants v
                LEFT JOIN v.color vc
                LEFT JOIN vc.translations vct WITH vct.language = :locale
                LEFT JOIN v.size vs
                LEFT JOIN vs.translations vst WITH vst.language = :locale
                LEFT JOIN v.optionValues vov
                LEFT JOIN vov.translations povt WITH povt.language = :locale
                LEFT JOIN vov.productOption po
                LEFT JOIN po.translations pot WITH pot.language = :locale
                LEFT JOIN p.category cat
                LEFT JOIN cat.translations ct WITH ct.language = :locale
                LEFT JOIN cat.rentalPacks rp
                LEFT JOIN p.saleUnit su
                WHERE p.slug = :slug OR all_t.slug = :slug";

        return $this->getEntityManager()->createQuery($dql)
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->getOneOrNullResult();
    }

    public function findAllOptimized(string $locale = 'fr'): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->leftJoin('p.saleUnit', 'su')
            ->leftJoin('p.style', 's')
            ->leftJoin('p.bookingConfiguration', 'b')
            ->leftJoin('p.pictures', 'pict')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('cat.translations', 'ct', 'WITH', 'ct.language = :locale')
            ->leftJoin('cat.rentalPacks', 'rp')
            ->leftJoin('rp.translations', 'rpt', 'WITH', 'rpt.language = :locale')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('v.color', 'vc')
            ->leftJoin('vc.translations', 'vct', 'WITH', 'vct.language = :locale')
            ->leftJoin('v.size', 'vs')
            ->leftJoin('vs.translations', 'vst', 'WITH', 'vst.language = :locale')
            ->leftJoin('v.optionValues', 'vov')
            ->leftJoin('vov.translations', 'vovt', 'WITH', 'vovt.language = :locale')
            ->leftJoin('vov.productOption', 'po')
            ->leftJoin('po.translations', 'pot', 'WITH', 'pot.language = :locale')
            ->addSelect('t', 'su', 's', 'b', 'pict', 'cat', 'ct', 'rp', 'rpt', 'v', 'vc', 'vct', 'vs', 'vst', 'vov', 'vovt', 'po', 'pot')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}
