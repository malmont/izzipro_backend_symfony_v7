<?php
namespace App\Services\CategoryService;

use App\Entity\Categories;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getAllCategories(): array
    {
        return $this->entityManager->getRepository(Categories::class)->findAll();
    }

    public function getProductsByCategory(?array $categoryIds, ?string $keyword, int $page, int $pageSize): array
    {
        $queryBuilder = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p');

        if ($categoryIds) {
            $queryBuilder->join('p.category', 'c')
                         ->andWhere('c.id IN (:categoryIds)')
                         ->setParameter('categoryIds', $categoryIds);
        }

        if ($keyword) {
            $queryBuilder->andWhere('p.name LIKE :keyword OR p.description LIKE :keyword')
                         ->setParameter('keyword', '%' . $keyword . '%');
        }

        // Appliquer la pagination
        $queryBuilder->setFirstResult(($page - 1) * $pageSize)
                     ->setMaxResults($pageSize);

        return $queryBuilder->getQuery()->getResult();
    }

    public function countTotalProducts(?array $categoryIds = null): int
    {
        $queryBuilder = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p');

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
