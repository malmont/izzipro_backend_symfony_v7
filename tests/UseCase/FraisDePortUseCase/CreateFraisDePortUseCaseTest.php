<?php

namespace App\Tests\UseCase\FraisDePortUseCase;

use App\Dto\FraisDePortInputDTO;
use App\Entity\Commande;
use App\Services\FraisDePortService\FraisDePortService;
use App\UseCase\FraisDePortUseCase\CreateFraisDePortUseCase;
use PHPUnit\Framework\TestCase;

class CreateFraisDePortUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {
        // Arrange
        $fraisDePortService = $this->createMock(FraisDePortService::class);
        $commande = $this->createMock(Commande::class);
        $inputDTO = new FraisDePortInputDTO(
            'Test Name',
            'Facture123',
            'TRACK999',
            15.50,
            10
        );

        $fraisDePortService->expects($this->once())
            ->method('createFraisDePort')
            ->with($commande, $inputDTO);

        $useCase = new CreateFraisDePortUseCase($fraisDePortService);

        // Act
        $useCase->execute($commande, $inputDTO);
    }
}
