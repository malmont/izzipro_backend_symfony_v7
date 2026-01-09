<?php

namespace App\Tests\Unit\Services\StockEvolutionService;

use App\Entity\InventoryMovements;
use App\Repository\InventoryMovementsRepository;
use App\Services\StockEvolutionService\StockEvolutionService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StockEvolutionServiceTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $repository;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(InventoryMovementsRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')
            ->with(InventoryMovements::class)
            ->willReturn($this->repository);

        $this->service = new StockEvolutionService($this->emProvider);
    }

    public function testGetStockEvolutionForCurrentWeek(): void
    {
        $this->setupRepositoryMock();

        $result = $this->service->getStockEvolutionForCurrentWeek();

        $this->assertCount(4, $result);
        $this->assertEquals([
            ['type' => 'Entrant', 'quantity' => 10],
            ['type' => 'Sortant', 'quantity' => 5],
            ['type' => 'Return', 'quantity' => 2],
            ['type' => 'Ajustement', 'quantity' => 1],
        ], $result);
    }

    public function testGetStockEvolutionForCurrentMonth(): void
    {
        $this->setupRepositoryMock();

        $result = $this->service->getStockEvolutionForCurrentMonth();

        $this->assertCount(4, $result);
        $this->assertEquals([
            ['type' => 'Entrant', 'quantity' => 10],
            ['type' => 'Sortant', 'quantity' => 5],
            ['type' => 'Return', 'quantity' => 2],
            ['type' => 'Ajustement', 'quantity' => 1],
        ], $result);
    }

    public function testGetStockEvolutionForCurrentYear(): void
    {
        $this->setupRepositoryMock();

        $result = $this->service->getStockEvolutionForCurrentYear();

        $this->assertCount(4, $result);
        $this->assertEquals([
            ['type' => 'Entrant', 'quantity' => 10],
            ['type' => 'Sortant', 'quantity' => 5],
            ['type' => 'Return', 'quantity' => 2],
            ['type' => 'Ajustement', 'quantity' => 1],
        ], $result);
    }

    private function setupRepositoryMock(): void
    {
        $this->repository->method('getTotalQuantityByMovementType')
            ->willReturnCallback(function ($start, $end, $type) {
                return match ($type) {
                    'Entrant' => 10,
                    'Sortant' => 5,
                    'Return' => 2,
                    'Ajustement' => 1,
                    default => 0,
                };
            });
    }
}
