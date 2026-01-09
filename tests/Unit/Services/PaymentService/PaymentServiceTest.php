<?php

namespace App\Tests\Unit\Services\PaymentService;

use App\Entity\Payments;
use App\Services\PaymentService\PaymentService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    public function testGetPaymentsByOrderSourceWrapsRepositoryCalls(): void
    {
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $expectedResult = ['payment1', 'payment2'];

        // Provider -> EM
        $emProvider->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($em);

        // EM -> Repository
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Payments::class)
            ->willReturn($repository);

        // Repository -> QueryBuilder
        $repository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('p')
            ->willReturn($qb);

        // QueryBuilder Chain
        // Note: each method returns $qb (self)
        // We use method return self for fluid interface
        $qb->method('leftJoin')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);

        // QueryBuilder -> Query
        $qb->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        // Query -> Result
        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($expectedResult);

        $service = new PaymentService($emProvider);
        $result = $service->getPaymentsByOrderSource(123, 7); // With days argument to trigger full chain

        $this->assertEquals($expectedResult, $result);
    }
}
