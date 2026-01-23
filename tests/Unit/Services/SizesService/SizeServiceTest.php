<?php

namespace App\Tests\Unit\Services\SizesService;

use App\Entity\Size;
use App\Services\SizesService\SizeService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

class SizeServiceTest extends TestCase
{
    public function testGetAllSizes()
    {
        $emProviderMock = $this->createMock(TenantEntityManagerProvider::class);
        $emMock = $this->createMock(EntityManagerInterface::class);
        $repositoryMock = $this->createMock(ObjectRepository::class);
        $sizeMock = $this->createMock(Size::class);

        $emProviderMock->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($emMock);

        $emMock->expects($this->once())
            ->method('getRepository')
            ->with(Size::class)
            ->willReturn($repositoryMock);

        $repositoryMock->expects($this->once())
            ->method('findAll')
            ->willReturn([$sizeMock]);

        $service = new SizeService($emProviderMock);
        $result = $service->getAllSizes();

        $this->assertCount(1, $result);
        $this->assertSame($sizeMock, $result[0]);
    }
}
