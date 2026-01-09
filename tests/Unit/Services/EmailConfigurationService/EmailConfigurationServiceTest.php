<?php

namespace App\Tests\Unit\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Repository\EmailConfigurationRepository;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EmailConfigurationServiceTest extends TestCase
{
    private $emProvider;
    private $repository;
    private $auth;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->repository = $this->createMock(EmailConfigurationRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $entityManager->method('getRepository')
            ->with(EmailConfiguration::class)
            ->willReturn($this->repository);

        $this->emProvider->method('getEntityManager')
            ->willReturn($entityManager);

        $this->service = new EmailConfigurationService($this->emProvider);
    }

    public function testFindOneByLocaleDelegatesToRepository(): void
    {
        $entity = new EmailConfiguration();
        $this->repository->expects($this->once())
            ->method('findOneByLocale')
            ->with('fr')
            ->willReturn($entity);

        $result = $this->service->findOneByLocale('fr');
        $this->assertSame($entity, $result);
    }

    public function testFindOneByLocaleReturnsNullIfNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('findOneByLocale')
            ->with('en')
            ->willReturn(null);

        $result = $this->service->findOneByLocale('en');
        $this->assertNull($result);
    }
}
