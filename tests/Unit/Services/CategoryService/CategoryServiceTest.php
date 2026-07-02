<?php

namespace App\Tests\Unit\Services\CategoryService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Services\CategoryService\CategoryService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class CategoryServiceTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')
            ->willReturn($this->entityManager);

        $this->service = new CategoryService($this->emProvider);
    }

    public function testGetAllCategories(): void
    {
        $repo = $this->createMock(MockCategoriesRepository::class);
        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Categories::class)
            ->willReturn($repo);

        $repo->expects($this->once())
            ->method('findAllByLocale')
            ->with('fr')
            ->willReturn(['cat1', 'cat2']);

        $result = $this->service->getAllCategories('fr');
        $this->assertEquals(['cat1', 'cat2'], $result);
    }

    public function testGetProductsByCategory(): void
    {
        $repo = $this->createMock(MockProductRepository::class);
        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Product::class)
            ->willReturn($repo);

        $repo->expects($this->once())
            ->method('findByCategoryAndFilters')
            ->with('fr', [1, 2], 'search', 1, 10, '12345', true, false)
            ->willReturn(['prod1']);

        $result = $this->service->getProductsByCategory('fr', [1, 2], 'search', 1, 10, '12345', true, false);
        $this->assertEquals(['prod1'], $result);
    }

    public function testCountTotalProductsWithCategories(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);
        $repo = $this->createMock(EntityRepository::class);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Product::class)
            ->willReturn($repo);

        $repo->expects($this->once())
            ->method('createQueryBuilder')
            ->with('p')
            ->willReturn($qb);

        $qb->expects($this->once())->method('join')->with('p.category', 'c')->willReturnSelf();
        $qb->expects($this->once())->method('andWhere')->with('c.id IN (:categoryIds)')->willReturnSelf();
        $qb->expects($this->once())->method('setParameter')->with('categoryIds', [1, 2])->willReturnSelf();
        
        // When NO keyword is provided, we only have one andWhere and one setParameter above
        
        $qb->expects($this->once())->method('select')->with('COUNT(DISTINCT p.id)')->willReturnSelf();
        $qb->expects($this->once())->method('getQuery')->willReturn($query);

        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(50);

        $result = $this->service->countTotalProducts('fr', [1, 2], null);
        $this->assertEquals(50, $result);
    }

    public function testCountTotalProductsWithoutCategories(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);
        $repo = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')->willReturn($repo);
        $repo->method('createQueryBuilder')->willReturn($qb);

        // Expect NO join/andWhere calls for categories
        $qb->expects($this->never())->method('join');
        $qb->expects($this->never())->method('andWhere');

        $qb->method('select')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getSingleScalarResult')->willReturn(100);

        $result = $this->service->countTotalProducts('fr');
        $this->assertEquals(100, $result);
    }

    public function testCountTotalProductsWithKeyword(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);
        $repo = $this->createMock(EntityRepository::class);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Product::class)
            ->willReturn($repo);

        $repo->expects($this->once())
            ->method('createQueryBuilder')
            ->with('p')
            ->willReturn($qb);

        $qb->expects($this->once())->method('leftJoin')
            ->with('p.translations', 't', 'WITH', 't.locale = :locale')
            ->willReturnSelf();
        
        $qb->expects($this->once())->method('andWhere')
            ->with('(LOWER(p.name) LIKE LOWER(:keyword) OR LOWER(p.description) LIKE LOWER(:keyword) OR LOWER(t.name) LIKE LOWER(:keyword) OR LOWER(t.description) LIKE LOWER(:keyword) OR LOWER(p.code) LIKE LOWER(:keyword))')
            ->willReturnSelf();
            
        $qb->expects($this->exactly(2))->method('setParameter')
            ->withConsecutive(
                ['keyword', '%search%'],
                ['locale', 'fr']
            )
            ->willReturnSelf();
        
        $qb->expects($this->once())->method('select')->with('COUNT(DISTINCT p.id)')->willReturnSelf();
        $qb->expects($this->once())->method('getQuery')->willReturn($query);

        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(5);

        $result = $this->service->countTotalProducts('fr', null, 'search');
        $this->assertEquals(5, $result);
    }
}

// Interfaces to help PHPUnit mock methods that don't exist in base EntityRepository
interface MockCategoriesRepository extends \Doctrine\Persistence\ObjectRepository
{
    public function findAllByLocale(string $locale);
}

interface MockProductRepository extends \Doctrine\Persistence\ObjectRepository
{
    public function findByCategoryAndFilters(string $locale, ?array $categoryIds, ?string $keyword, int $page, int $pageSize, ?string $barcode, ?bool $isWeb, ?bool $isPos);
}
