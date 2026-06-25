<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\Vehicle;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GemsuiteVehicleSyncTest extends TestCase
{
    public function testSyncVehiclesMapsFieldsCorrectly(): void
    {
        // 1. Create mocks for dependencies
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $stockCalculator = $this->createMock(GemsuiteStockCalculator::class);
        $logger = $this->createMock(LoggerInterface::class);
        $imageUrlBuilder = $this->createMock(GemsuiteImageUrlBuilder::class);
        $cache = $this->createMock(TenantCacheService::class);

        // 2. Set up repositories
        $vehicleRepo = $this->createMock(EntityRepository::class);
        $productRepo = $this->createMock(EntityRepository::class);
        $entrepriseRepo = $this->createMock(EntityRepository::class);

        // 3. Set up EM / EM Provider expectations
        $emProvider->method('getEntityManager')->willReturn($entityManager);
        $entityManager->method('getRepository')->will($this->returnValueMap([
            [Vehicle::class, $vehicleRepo],
            [Product::class, $productRepo],
            [Entreprise::class, $entrepriseRepo]
        ]));

        // Mock Entreprise info
        $entreprise = $this->createMock(Entreprise::class);
        $entreprise->method('getGemsuiteIdentifier')->willReturn('test-company');
        $entrepriseRepo->method('findOneBy')->willReturn($entreprise);

        // Mock Product
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(10);
        $product->method('getName')->willReturn('Roulotte test');
        $product->method('getPrice')->willReturn(12000.0);
        $productRepo->method('findOneBy')->willReturn($product);

        // Mock Vehicle search (not found initially, so it gets created)
        $vehicleRepo->method('findOneBy')->willReturn(null);

        // Expect image builder to build correct URL for main picture
        $imageUrlBuilder->expects($this->once())
            ->method('buildUrl')
            ->with('test-company', '/images/medias/0.png')
            ->willReturn('https://app.gem-books.com/?layout=image&d=test-company&filename=/images/medias/0.png');

        // Expect cache invalidation
        $cache->expects($this->once())
            ->method('delete')
            ->with('vehicles_carousel')
            ->willReturn(true);

        // Expect entity manager persist
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Vehicle $vehicle) use ($product) {
                $this->assertEquals(60, $vehicle->getGemsuiteVehicleId());
                $this->assertEquals($product, $vehicle->getProduct());
                $this->assertEquals('Prolite 12V 2023', $vehicle->getTitle());
                $this->assertEquals('Roulotte ultralégère', $vehicle->getDescription());
                $this->assertEquals(2023, $vehicle->getYear());
                $this->assertEquals('BLANC', $vehicle->getColor());
                $this->assertEquals(1, $vehicle->getTransmission());
                $this->assertEquals(1, $vehicle->getGasType());
                $this->assertTrue($vehicle->getNewVehicle());
                $this->assertFalse($vehicle->getFeaturedVehicle());
                $this->assertTrue($vehicle->getWebDisplay());
                $this->assertEquals('caravane-201-2023-PROLITE-12V-caravane-201', $vehicle->getSlug());
                $this->assertEquals('https://app.gem-books.com/?layout=image&d=test-company&filename=/images/medias/0.png', $vehicle->getPicture());
                return true;
            }));

        $entityManager->expects($this->once())->method('flush');

        // 4. Run the service
        $service = new GemsuiteRentalWorkaroundService(
            $emProvider,
            $stockCalculator,
            $logger,
            $imageUrlBuilder,
            $cache
        );

        $vehiclesData = [
            [
                "id" => 60,
                "product_id" => 8618,
                "year" => 2023,
                "color" => "BLANC",
                "transmission" => 1,
                "gas_type" => 1,
                "web_display" => 1,
                "featured_vehicle" => 0,
                "new_vehicle" => 1,
                "web_slug" => "caravane-201-2023-PROLITE-12V-caravane-201",
                "web_title" => "Prolite 12V 2023",
                "web_description" => "Roulotte ultralégère",
                "media" => [
                    [
                        "id" => 555,
                        "nom" => "1.jpg",
                        "media_type_id" => 2,
                        "path" => "/2eca80b4f43ca20202513083f166d7ca/e18ccef16398ddbda9963f5394d1699c/1.jpg"
                    ],
                    [
                        "id" => 550,
                        "nom" => "Principale",
                        "media_type_id" => 1,
                        "path" => "/images/medias/0.png"
                    ]
                ]
            ]
        ];

        $service->syncVehicles($vehiclesData);
    }
}
