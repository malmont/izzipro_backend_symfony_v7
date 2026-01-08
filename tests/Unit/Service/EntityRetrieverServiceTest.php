<?php

namespace App\Tests\Unit\Service;

use App\Services\EntityRetrieverService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use stdClass;

class EntityRetrieverServiceTest extends TestCase
{
    private $tenantEmProvider;
    private $em;
    private $service;

    protected function setUp(): void
    {
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->em);

        $this->service = new EntityRetrieverService($this->tenantEmProvider);
    }

    public function testFindOrFailReturnsEntityWhenFound(): void
    {
        $entity = new stdClass();
        $entity->id = 1;

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($entity);

        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(stdClass::class)
            ->willReturn($repository);

        $result = $this->service->findOrFail(stdClass::class, 1);

        $this->assertSame($entity, $result);
    }

    public function testFindOrFailThrowsExceptionWhenNotFound(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(stdClass::class)
            ->willReturn($repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Custom error message');

        $this->service->findOrFail(stdClass::class, 999, 'Custom error message');
    }
}
