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
            ->select('SUM(p.price * p.quantity) as stockValue')
            ->where('p.quantity > 0')
            ->andWhere('p.price IS NOT NULL');

        $result = $qb->getQuery()->getSingleScalarResult();

        return ((float) ($result ?? 0.0)) / 100.0;
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
            $queryBuilder->andWhere('(LOWER(p.name) LIKE LOWER(:keyword) OR LOWER(p.description) LIKE LOWER(:keyword) OR LOWER(t.name) LIKE LOWER(:keyword) OR LOWER(t.description) LIKE LOWER(:keyword) OR LOWER(p.code) LIKE LOWER(:keyword))')
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
        // Listes de produits (meilleures ventes, nouveautés…) : une requête unique joignant toutes les collections faisait
        // un produit cartésien (1,8 s à froid, 09/10/2026). Identifiants d'abord, puis une requête par collection pour
        // tous les produits de la liste, mêmes filtres de langue qu'avant.
        $qb = $this->createQueryBuilder('p')->select('p.id')->orderBy('p.id', 'ASC');
        foreach ($criteria as $field => $value) {
            $qb->andWhere("p.{$field} = :{$field}")->setParameter($field, $value);
        }
        $ids = array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
        if ($ids === []) {
            return [];
        }
        $em = $this->getEntityManager();
        $products = $em->createQuery(
            'SELECT p, t, su, s, b FROM App\Entity\Product p LEFT JOIN p.translations t WITH t.locale = :locale
             LEFT JOIN p.saleUnit su LEFT JOIN p.style s LEFT JOIN p.bookingConfiguration b WHERE p.id IN (:ids) ORDER BY p.id ASC'
        )->setParameter('ids', $ids)->setParameter('locale', $locale)->getResult();
        $em->createQuery('SELECT p, pict FROM App\Entity\Product p LEFT JOIN p.pictures pict WHERE p.id IN (:ids)')->setParameter('ids', $ids)->getResult();
        $em->createQuery(
            'SELECT p, cat, ct, rp, rpt FROM App\Entity\Product p LEFT JOIN p.category cat LEFT JOIN cat.translations ct WITH ct.language = :locale
             LEFT JOIN cat.rentalPacks rp LEFT JOIN rp.translations rpt WITH rpt.language = :locale WHERE p.id IN (:ids)'
        )->setParameter('ids', $ids)->setParameter('locale', $locale)->getResult();
        $em->createQuery(
            'SELECT p, v, vc, vct, vs, vst FROM App\Entity\Product p LEFT JOIN p.variants v
             LEFT JOIN v.color vc LEFT JOIN vc.translations vct WITH vct.language = :locale
             LEFT JOIN v.size vs LEFT JOIN vs.translations vst WITH vst.language = :locale WHERE p.id IN (:ids)'
        )->setParameter('ids', $ids)->setParameter('locale', $locale)->getResult();
        $em->createQuery(
            'SELECT v, vov, vovt, po, pot FROM App\Entity\ProductVariant v LEFT JOIN v.optionValues vov
             LEFT JOIN vov.translations vovt WITH vovt.language = :locale
             LEFT JOIN vov.productOption po LEFT JOIN po.translations pot WITH pot.language = :locale WHERE v.product IN (:ids)'
        )->setParameter('ids', $ids)->setParameter('locale', $locale)->getResult();
        $em->createQuery('SELECT v, ci, civ FROM App\Entity\ProductVariant v LEFT JOIN v.productCustomizationImages ci LEFT JOIN ci.optionValues civ WHERE v.product IN (:ids)')
            ->setParameter('ids', $ids)->getResult();

        return $products;
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
        // Une seule requête joignait photos, variantes, options, traductions et forfaits : produit cartésien (2,2 s pour
        // une fiche de démo, 09/10/2026). Désormais : l'identifiant d'abord, puis une petite requête par collection,
        // qui remplissent le même produit (mêmes filtres de langue qu'avant).
        $id = $this->getEntityManager()->createQuery(
            'SELECT DISTINCT p.id FROM App\Entity\Product p LEFT JOIN p.translations all_t WHERE p.slug = :slug OR all_t.slug = :slug'
        )->setParameter('slug', $slug)->setMaxResults(1)->getOneOrNullResult();

        return $id === null ? null : $this->loadForDetail((int) $id['id'], $locale);
    }

    /** Produit complet pour sa fiche, chargé collection par collection (sans produit cartésien) */
    public function loadForDetail(int $id, string $locale): ?Product
    {
        $em = $this->getEntityManager();
        $product = $em->createQuery(
            "SELECT p, t, t_fr, b, s, su FROM App\Entity\Product p
             LEFT JOIN p.translations t WITH t.locale = :locale
             LEFT JOIN p.translations t_fr WITH t_fr.locale = 'fr'
             LEFT JOIN p.bookingConfiguration b LEFT JOIN p.style s
             LEFT JOIN p.saleUnit su
             WHERE p.id = :id"
        )->setParameter('id', $id)->setParameter('locale', $locale)->getOneOrNullResult();
        if ($product === null) {
            return null;
        }
        $em->createQuery('SELECT p, pict FROM App\Entity\Product p LEFT JOIN p.pictures pict WHERE p.id = :id')->setParameter('id', $id)->getResult();
        $em->createQuery(
            'SELECT p, v, vc, vct, vs, vst FROM App\Entity\Product p LEFT JOIN p.variants v
             LEFT JOIN v.color vc LEFT JOIN vc.translations vct WITH vct.language = :locale
             LEFT JOIN v.size vs LEFT JOIN vs.translations vst WITH vst.language = :locale
             WHERE p.id = :id'
        )->setParameter('id', $id)->setParameter('locale', $locale)->getResult();
        $em->createQuery(
            'SELECT v, vov, povt, po, pot FROM App\Entity\ProductVariant v LEFT JOIN v.optionValues vov
             LEFT JOIN vov.translations povt WITH povt.language = :locale
             LEFT JOIN vov.productOption po LEFT JOIN po.translations pot WITH pot.language = :locale
             WHERE v.product = :id'
        )->setParameter('id', $id)->setParameter('locale', $locale)->getResult();
        // Combinaisons de personnalisation (champ customizable) : une requête au lieu d'une par variante
        $em->createQuery('SELECT v, ci, civ FROM App\Entity\ProductVariant v LEFT JOIN v.productCustomizationImages ci LEFT JOIN ci.optionValues civ WHERE v.product = :id')
            ->setParameter('id', $id)->getResult();
        $em->createQuery(
            'SELECT p, cat, ct, rp FROM App\Entity\Product p LEFT JOIN p.category cat
             LEFT JOIN cat.translations ct WITH ct.language = :locale LEFT JOIN cat.rentalPacks rp WHERE p.id = :id'
        )->setParameter('id', $id)->setParameter('locale', $locale)->getResult();

        return $product;
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
