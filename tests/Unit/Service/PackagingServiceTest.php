<?php

namespace App\Tests\Unit\Service;

use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use App\Entity\ShippingClass;
use App\Exception\ItemTooLargeForPackagingException;
use App\Services\ShippingService\PackagingService;
use DVDoug\BoxPacker\Rotation;
use PHPUnit\Framework\TestCase;
use DVDoug\BoxPacker\Exception\NoBoxesAvailableException;

class PackagingServiceTest extends TestCase
{
    /**
     * Crée un Mock intelligent de ProductShipping.
     */
    private function createMockItem(
        int $width, int $length, int $depth, int $weight, 
        string $description, ?int $shippingClassId = null
    ): ProductShipping {
        
        $builder = $this->getMockBuilder(ProductShipping::class)
            ->disableOriginalConstructor();

        // Liste des méthodes à mocker pour l'Item
        $methodsToMock = [
            'getWidth', 'getLength', 'getDepth', 'getWeight', 
            'getDescription', 'getKeepFlat', 'getShippingClassEntity',
            'getAllowedRotation'
        ];

        $onlyMethods = [];
        $addMethods = [];

        foreach ($methodsToMock as $method) {
            if (method_exists(ProductShipping::class, $method)) {
                $onlyMethods[] = $method;
            } else {
                $addMethods[] = $method;
            }
        }

        if (!empty($onlyMethods)) {
            $builder->onlyMethods($onlyMethods);
        }
        if (!empty($addMethods)) {
            $builder->addMethods($addMethods);
        }

        $item = $builder->getMock();

        // --- CONFIGURATION ---
        $item->method('getWidth')->willReturn($width);
        $item->method('getLength')->willReturn($length);
        $item->method('getDepth')->willReturn($depth);
        $item->method('getWeight')->willReturn($weight);
        $item->method('getDescription')->willReturn($description);
        
        if (method_exists($item, 'getKeepFlat') || in_array('getKeepFlat', $addMethods)) {
            $item->method('getKeepFlat')->willReturn(false);
        }

        if (method_exists($item, 'getAllowedRotation') || in_array('getAllowedRotation', $addMethods)) {
            $item->method('getAllowedRotation')->willReturn(Rotation::BestFit);
        }

        // --- CORRECTION SHIPPING CLASS ---
        if ($shippingClassId !== null) {
            // On applique la logique "Smart Mock" aussi pour ShippingClass
            // pour éviter l'erreur "CannotUseAddMethodsException"
            $classBuilder = $this->getMockBuilder(ShippingClass::class)
                ->disableOriginalConstructor();

            if (method_exists(ShippingClass::class, 'getId')) {
                $classBuilder->onlyMethods(['getId']);
            } else {
                $classBuilder->addMethods(['getId']);
            }

            $mockClass = $classBuilder->getMock();
            $mockClass->method('getId')->willReturn($shippingClassId);

            $item->method('getShippingClassEntity')->willReturn($mockClass);
        } else {
            $item->method('getShippingClassEntity')->willReturn(null);
        }

        return $item;
    }

    /**
     * Crée un Mock intelligent de PackagingType.
     */
    private function createMockBox(
        string $ref, int $width, int $length, int $depth, int $maxWeight
    ): PackagingType {
        
        $builder = $this->getMockBuilder(PackagingType::class)
            ->disableOriginalConstructor();

        $methodsToMock = [
            'getReference', 'getOuterWidth', 'getOuterLength', 'getOuterDepth',
            'getEmptyWeight', 'getInnerWidth', 'getInnerLength', 'getInnerDepth', 
            'getMaxWeight'
        ];

        $onlyMethods = [];
        $addMethods = [];

        foreach ($methodsToMock as $method) {
            if (method_exists(PackagingType::class, $method)) {
                $onlyMethods[] = $method;
            } else {
                $addMethods[] = $method;
            }
        }

        if (!empty($onlyMethods)) {
            $builder->onlyMethods($onlyMethods);
        }
        if (!empty($addMethods)) {
            $builder->addMethods($addMethods);
        }

        $box = $builder->getMock();

        $box->method('getReference')->willReturn($ref);
        $box->method('getOuterWidth')->willReturn($width);
        $box->method('getOuterLength')->willReturn($length);
        $box->method('getOuterDepth')->willReturn($depth);
        $box->method('getEmptyWeight')->willReturn(0);
        $box->method('getInnerWidth')->willReturn($width - 2);
        $box->method('getInnerLength')->willReturn($length - 2);
        $box->method('getInnerDepth')->willReturn($depth - 2);
        $box->method('getMaxWeight')->willReturn($maxWeight);

        return $box;
    }

    public function testComputeParcelsPacksSimpleItem(): void
    {
        $item = $this->createMockItem(10, 10, 10, 1000, 'Petit Objet');
        $box = $this->createMockBox('BOX-M', 20, 20, 20, 10000);

        $service = new PackagingService();
        $packedBoxes = $service->computeParcels([$item], [$box]);

        // Assertion Simplifiée : Si on a 1 colis, c'est que l'item est rentré.
        $this->assertCount(1, $packedBoxes);
    }

    public function testComputeParcelsSeparatesByShippingClass(): void
    {
        $itemA = $this->createMockItem(10, 10, 10, 500, 'Vase', 1);
        $itemB = $this->createMockItem(10, 10, 10, 500, 'Livre', 2);
        $box = $this->createMockBox('BIG-BOX', 40, 40, 40, 10000);

        $service = new PackagingService();
        $packedBoxes = $service->computeParcels([$itemA, $itemB], [$box]);

        // Le test crucial : On doit avoir 2 colis car les IDs de classe sont différents
        $this->assertCount(2, $packedBoxes);
    }

    public function testComputeParcelsThrowsExceptionIfItemTooLarge(): void
    {
        $item = $this->createMockItem(100, 100, 100, 5000, 'Objet Géant', 1);
        $box = $this->createMockBox('SMALL-BOX', 10, 10, 10, 10000);

        $service = new PackagingService();

        $this->expectException(ItemTooLargeForPackagingException::class);
        $service->computeParcels([$item], [$box]);
    }
}