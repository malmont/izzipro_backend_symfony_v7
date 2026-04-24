<?php
namespace App\Services\CategoryService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Services\TenantEntityManagerProvider; 
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
    private EntityManagerInterface $em;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->em = $emProvider->getEntityManager();
    }

    public function getAllCategories(string $locale): array
    {
        return $this->em->getRepository(Categories::class)->findAllByLocale($locale);
    }

    public function getProductsByCategory(string $locale, ?array $categoryIds, ?string $keyword, int $page, int $pageSize, ?string $barcode, ?bool $isWeb, ?bool $isPos): array
    {
        return $this->em->getRepository(Product::class)
            ->findByCategoryAndFilters($locale, $categoryIds, $keyword, $page, $pageSize, $barcode, $isWeb, $isPos);
    }

    public function countTotalProducts(string $locale, ?array $categoryIds = null, ?string $keyword = null): int
    {
        $queryBuilder = $this->em->getRepository(Product::class)->createQueryBuilder('p');

        if ($categoryIds) {
            $queryBuilder->join('p.category', 'c')
                         ->andWhere('c.id IN (:categoryIds)')
                         ->setParameter('categoryIds', $categoryIds);
        }

        if ($keyword) {
            $queryBuilder->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
                         ->andWhere('(p.name LIKE :keyword OR p.description LIKE :keyword OR t.name LIKE :keyword OR t.description LIKE :keyword)')
                         ->setParameter('keyword', '%' . $keyword . '%')
                         ->setParameter('locale', $locale);
        }

        return $queryBuilder->select('COUNT(DISTINCT p.id)')
                            ->getQuery()
                            ->getSingleScalarResult();
    }
}