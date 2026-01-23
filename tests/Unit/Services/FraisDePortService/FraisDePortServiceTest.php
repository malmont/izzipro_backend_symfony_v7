<?php

namespace App\Tests\Services\FraisDePortService;

use App\Dto\FraisDePortInputDTO;
use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Services\FraisDePortService\FraisDePortService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

class FraisDePortServiceTest extends TestCase
{
    public function testGetFraisDePortByCommande()
    {
        $emProviderMock = $this->createMock(TenantEntityManagerProvider::class);
        $service = new FraisDePortService($emProviderMock);

        $commandeMock = $this->createMock(Commande::class);
        $fraisDePortMock = $this->createMock(FraisDePort::class);

        $commandeMock->expects($this->once())
            ->method('getFraisDePort')
            ->willReturn($fraisDePortMock);

        $result = $service->getFraisDePortByCommande($commandeMock);

        $this->assertSame($fraisDePortMock, $result);
    }

    public function testCreateFraisDePort()
    {
        $emProviderMock = $this->createMock(TenantEntityManagerProvider::class);
        $emMock = $this->createMock(EntityManagerInterface::class);
        $transporteurRepositoryMock = $this->createMock(ObjectRepository::class);
        $commandeMock = $this->createMock(Commande::class);
        $transporteurMock = $this->createMock(Transporteur::class);

        $inputDTO = new FraisDePortInputDTO('Name', 'Facture', 'Track123', 10.5, 1);

        $emProviderMock->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($emMock);

        $emMock->expects($this->once())
            ->method('getRepository')
            ->with(Transporteur::class)
            ->willReturn($transporteurRepositoryMock);

        $transporteurRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($transporteurMock);

        $emMock->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(FraisDePort::class));

        $emMock->expects($this->once())
            ->method('flush');

        $service = new FraisDePortService($emProviderMock);
        $service->createFraisDePort($commandeMock, $inputDTO);
    }

    public function testDeleteFraisDePort()
    {
        $emProviderMock = $this->createMock(TenantEntityManagerProvider::class);
        $emMock = $this->createMock(EntityManagerInterface::class);
        $commandeMock = $this->createMock(Commande::class);
        $fraisDePortMock = $this->createMock(FraisDePort::class);

        $commandeMock->expects($this->once())
            ->method('getFraisDePort')
            ->willReturn($fraisDePortMock);

        $emProviderMock->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($emMock);

        $emMock->expects($this->once())
            ->method('remove')
            ->with($fraisDePortMock);

        $emMock->expects($this->once())
            ->method('flush');

        $service = new FraisDePortService($emProviderMock);
        $service->deleteFraisDePort($commandeMock);
    }

    public function testDeleteFraisDePortDoesNothingIfNull()
    {
        $emProviderMock = $this->createMock(TenantEntityManagerProvider::class);
        $commandeMock = $this->createMock(Commande::class);

        $commandeMock->expects($this->once())
            ->method('getFraisDePort')
            ->willReturn(null);

        $emProviderMock->expects($this->never())
            ->method('getEntityManager');

        $service = new FraisDePortService($emProviderMock);
        $service->deleteFraisDePort($commandeMock);
    }
}
