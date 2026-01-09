<?php

namespace App\Tests\Unit\Services\ShippingService;

use App\Dto\RateOptionDto;
use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use App\Services\ShippingService\EasyPostService;
use App\Services\ShippingService\PackagingService;
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedBoxList;
use DVDoug\BoxPacker\PackedItemList;
use PHPUnit\Framework\TestCase;

class ShippingServiceTest extends TestCase
{
    // --- TEST 1 : LOGIQUE PURE ---
    public function testAggregateRatesSumsTotalsAndTakesMaxDays(): void
    {
        $rates = [
            new RateOptionDto('UPS', 'Standard', 10.00, 'USD', 3),
            new RateOptionDto('UPS', 'Standard', 5.00, 'USD', 5),
            new RateOptionDto('FedEx', 'Express', 20.00, 'USD', 1),
        ];

        $service = new ShippingService(
            $this->createMock(TenantEntityManagerProvider::class),
            $this->createMock(EasyPostService::class),
            $this->createMock(PackagingService::class)
        );

        $aggregated = $service->aggregateRates($rates);

        $this->assertCount(2, $aggregated);

        $ups = null;
        foreach ($aggregated as $r) {
            if ($r->carrier === 'UPS') $ups = $r;
        }

        $this->assertNotNull($ups);
        $this->assertEquals(15.00, $ups->price);
        $this->assertEquals(5, $ups->estimatedDays);
    }

    // --- TEST 2 : ORCHESTRATION (GetRates) ---

    public function testGetRatesOrchestratesPackingAndEasyPostCall(): void
    {
        // 1. MOCK DES ENTITÉS (Boîte)
        $boxTemplate = $this->createMock(PackagingType::class);
        $boxTemplate->method('getOuterLength')->willReturn(100);
        $boxTemplate->method('getOuterWidth')->willReturn(100);
        $boxTemplate->method('getOuterDepth')->willReturn(100);
        $boxTemplate->method('getEmptyWeight')->willReturn(1000);
        $boxTemplate->method('getInnerLength')->willReturn(90);
        $boxTemplate->method('getInnerWidth')->willReturn(90);
        $boxTemplate->method('getInnerDepth')->willReturn(90);

        // 2. INSTANCIATION RÉELLE DE PACKEDBOX (Correction Type)
        $packedBox = new PackedBox(
            $boxTemplate,
            new PackedItemList(),
            0,
            0,
            0,
            0
        );

        $packedBoxList = new PackedBoxList();
        $packedBoxList->insert($packedBox);

        // 3. MOCK DOCTRINE
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findAll')->willReturn([$boxTemplate]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // 4. MOCK PACKAGER
        $packager = $this->createMock(PackagingService::class);
        $packager->expects($this->once())
            ->method('computeParcels')
            ->willReturn($packedBoxList);

        // 5. MOCK EASYPOST
        $easyPost = $this->createMock(EasyPostService::class);

        $easyPost->expects($this->once())
            ->method('getRates')
            ->with($this->callback(function ($payload) {
                $p = $payload['parcel'];
                // Vérif Poids (1000g * 0.035274) = 35.274
                $weightOk = abs($p['weight'] - 35.274) < 0.01;
                return $weightOk;
            }))
            ->willReturn([
                (object)[
                    'carrier' => 'UPS',
                    'service' => 'Standard',
                    'rate' => 12.50,
                    'currency' => 'USD',
                    'est_delivery_days' => 3
                ]
            ]);

        // 6. EXECUTION
        $service = new ShippingService($emProvider, $easyPost, $packager);

        $items = [$this->createMock(ProductShipping::class)];
        $result = $service->getRates($items, ['addr_to'], ['addr_from'], ['ca_123']);

        $this->assertCount(1, $result);
        $this->assertEquals(12.50, $result[0]->price);
    }

    // --- TEST 3 : ACHAT (Purchase) ---

    public function testPurchaseCallsEasyPostBuyForEachParcel(): void
    {
        // Configuration similaire
        $boxTemplate = $this->createMock(PackagingType::class);
        $boxTemplate->method('getOuterLength')->willReturn(100);
        $boxTemplate->method('getOuterWidth')->willReturn(100);
        $boxTemplate->method('getOuterDepth')->willReturn(100);
        $boxTemplate->method('getEmptyWeight')->willReturn(500);
        $boxTemplate->method('getInnerLength')->willReturn(90);
        $boxTemplate->method('getInnerWidth')->willReturn(90);
        $boxTemplate->method('getInnerDepth')->willReturn(90);

        // Instantiation réelle avec PackedItemList
        $packedBox = new PackedBox(
            $boxTemplate,
            new PackedItemList(),
            0,
            0,
            0,
            0
        );

        $packedBoxList = new PackedBoxList();
        $packedBoxList->insert($packedBox);
        $packedBoxList->insert($packedBox);

        // Mocks Services
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findAll')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $emProvider->method('getEntityManager')->willReturn($em);

        $packager = $this->createMock(PackagingService::class);
        $packager->method('computeParcels')->willReturn($packedBoxList);

        $easyPost = $this->createMock(EasyPostService::class);
        $easyPost->expects($this->exactly(2))
            ->method('createShipmentAndBuy')
            ->willReturn(['label_url' => 'pdf', 'tracking_code' => '123']);

        // Execution
        $service = new ShippingService($emProvider, $easyPost, $packager);

        $labels = $service->purchase([], [], [], 'ca_123', 'Standard');

        $this->assertCount(2, $labels);
    }
}
