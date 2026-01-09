<?php

namespace App\Tests\Unit\Services\EntrepriseService;

use App\Dto\EntrepriseDto;
use App\Entity\Entreprise;
use App\Entity\EntrepriseTranslation;
use App\Repository\EntrepriseRepository;
use App\Services\EntrepriseService\EntrepriseService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EntrepriseServiceTest extends TestCase
{
    private $emProvider;
    private $repository;
    private $entityManager;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->repository = $this->createMock(EntrepriseRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Setup mocked EM to return mocked repository
        $this->entityManager->method('getRepository')
            ->with(Entreprise::class)
            ->willReturn($this->repository);

        $this->emProvider->method('getEntityManager')
            ->willReturn($this->entityManager);

        $this->service = new EntrepriseService($this->emProvider);
    }

    public function testGetEntrepriseByIdAndLocaleReturnsNullIfNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('findByIdAndLocale')
            ->with(999, 'fr')
            ->willReturn(null);

        $result = $this->service->getEntrepriseByIdAndLocale(999, 'example.com', 'fr');
        $this->assertNull($result);
    }

    public function testGetEntrepriseByIdAndLocaleReturnsDtoIfFound(): void
    {
        $entity = new Entreprise();
        $entity->setName('Test Corp');
        // Setting ID via reflection since it might not have setter
        $reflection = new \ReflectionClass($entity);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);

        $this->repository->expects($this->once())
            ->method('findByIdAndLocale')
            ->with(1, 'en')
            ->willReturn($entity);

        $result = $this->service->getEntrepriseByIdAndLocale(1, 'example.org', 'en');

        $this->assertInstanceOf(EntrepriseDto::class, $result);
        $this->assertEquals('Test Corp', $result->name);
    }

    public function testCreateEntreprisePersistsData(): void
    {
        $dto = new EntrepriseDto();
        $dto->name = 'New Corp';
        $dto->email = 'contact@newcorp.com';
        $dto->conditionOfUse = 'TOS content';

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Entreprise::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // We assume EM is called again inside createEntreprise (via emProvider)
        // Since we mocked getEntityManager in setUp via emProvider which returns the same $entityManager instance,
        // it should work.

        $resultDto = $this->service->createEntreprise($dto);

        $this->assertInstanceOf(EntrepriseDto::class, $resultDto);
        $this->assertEquals('New Corp', $resultDto->name);
    }
}
