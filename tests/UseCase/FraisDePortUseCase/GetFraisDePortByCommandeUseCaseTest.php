<?php

namespace App\Tests\UseCase\FraisDePortUseCase;

use App\Dto\FraisDePortOutputDTO;
use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Services\FraisDePortService\FraisDePortService;
use App\UseCase\FraisDePortUseCase\GetFraisDePortByCommandeUseCase;
use PHPUnit\Framework\TestCase;

class GetFraisDePortByCommandeUseCaseTest extends TestCase
{
    public function testExecuteReturnsDTOWhenFraisDePortFound()
    {
        // Arrange
        $fraisDePortService = $this->createMock(FraisDePortService::class);
        $commande = $this->createMock(Commande::class);
        $host = 'http://localhost';

        $fraisDePort = $this->createMock(FraisDePort::class);
        $transporteur = $this->createMock(Transporteur::class);

        $fraisDePortService->method('getFraisDePortByCommande')
            ->with($commande)
            ->willReturn($fraisDePort);

        $fraisDePort->method('getId')->willReturn(1);
        $fraisDePort->method('getName')->willReturn('Shipping Name');
        $fraisDePort->method('getFacture')->willReturn('INV-123');
        $fraisDePort->method('getTracknumber')->willReturn('TRACK123');
        $fraisDePort->method('getPrice')->willReturn(10.50);
        $fraisDePort->method('getTransporteur')->willReturn($transporteur);

        $transporteur->method('getId')->willReturn(5);
        $transporteur->method('getName')->willReturn('Carrier Name');
        $transporteur->method('getLogo')->willReturn('logo.png');
        $transporteur->method('getContact')->willReturn('contact@carrier.com');

        $useCase = new GetFraisDePortByCommandeUseCase($fraisDePortService);
        // Act
        $result = $useCase->execute($commande, $host);

        // Assert
        $this->assertInstanceOf(FraisDePortOutputDTO::class, $result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('Shipping Name', $result->name);
        $this->assertEquals('http://localhost/assets/uploads/Carrier/logo.png', $result->image);
        $this->assertEquals(5, $result->transporteur['id']);
        $this->assertEquals('Carrier Name', $result->transporteur['name']);
        $this->assertEquals('contact@carrier.com', $result->transporteur['contact']);
    }

    public function testExecuteReturnsNullWhenFraisDePortNotFound()
    {
        // Arrange
        $fraisDePortService = $this->createMock(FraisDePortService::class);
        $commande = $this->createMock(Commande::class);
        $host = 'http://localhost';
        $fraisDePortService->method('getFraisDePortByCommande')
            ->with($commande)
            ->willReturn(null);
        $useCase = new GetFraisDePortByCommandeUseCase($fraisDePortService);
        // Act
        $result = $useCase->execute($commande, $host);
        // Assert
        $this->assertNull($result);
    }
}
