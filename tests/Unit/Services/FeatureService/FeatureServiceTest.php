<?php

namespace App\Tests\Unit\Services\FeatureService;

use App\Entity\Feature;
use App\Repository\FeatureRepository;
use App\Services\FeatureService\FeatureService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class FeatureServiceTest extends TestCase
{
    public function testGetAllFeaturesByLocale()
    {
        // Mock dependencies
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(FeatureRepository::class);

        // Expectations
        $emProvider->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($entityManager);

        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Feature::class)
            ->willReturn($repository);

        $locale = 'fr';
        $features = [new Feature(), new Feature()];

        $repository->expects($this->once())
            ->method('findAllByLocale')
            ->with($locale)
            ->willReturn($features);

        // Instantiate service
        $service = new FeatureService($emProvider);

        // Call method
        $result = $service->getAllFeaturesByLocale($locale);

        // Assertions
        $this->assertSame($features, $result);
    }
}
