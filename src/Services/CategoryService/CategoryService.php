<?php
namespace App\Services\CategoryService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Services\TenantEntityManagerProvider; 

class CategoryService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllCategories(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Categories::class)->findAll();
    }

    public function getProductsByCategory(?array $categoryIds, ?string $keyword, int $page, int $pageSize, ?string $barcode, ?bool $isWeb, ?bool $isPos): array
    {
        $em = $this->emProvider->getEntityManager();
        $queryBuilder = $em->getRepository(Product::class)->createQueryBuilder('p');

        if ($categoryIds) {
            $queryBuilder->join('p.category', 'c')
                        ->andWhere('c.id IN (:categoryIds)')
                        ->setParameter('categoryIds', $categoryIds);
        }

        if ($keyword) {
            $queryBuilder->andWhere('(p.name LIKE :keyword OR p.description LIKE :keyword)')
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

        return $queryBuilder->getQuery()->getResult();
    }

    public function countTotalProducts(?array $categoryIds = null): int
    {
        $em = $this->emProvider->getEntityManager();
        $queryBuilder = $em->getRepository(Product::class)->createQueryBuilder('p');

        if ($categoryIds) {
            $queryBuilder->join('p.category', 'c')
                         ->andWhere('c.id IN (:categoryIds)')
                         ->setParameter('categoryIds', $categoryIds);
        }

        return $queryBuilder->select('COUNT(p.id)')
                            ->getQuery()
                            ->getSingleScalarResult();
    }
}