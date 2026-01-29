<?php

namespace App\Tests\UseCase\AdressUseCase;

use App\Dto\AdressInputDTO;
use App\Entity\User;
use App\Entity\Adress;
use App\Services\AdressService\AdressService;
use App\UseCase\AdressUseCase\CreateAdressUseCase;
use PHPUnit\Framework\TestCase;

class CreateAdressUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {
        // Arrange
        $adressService = $this->createMock(AdressService::class);
        $user = $this->createMock(User::class);
        $inputData = [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'addressLineOne' => '123 Main St',
            'city' => 'Montreal',
            'province' => 'QC',
            'zipCode' => 'H3Z 2Y7',
            'country' => 'Canada',
            'contactNumber' => '5141234567'
        ];
        $inputDTO = new AdressInputDTO($inputData);
        $expectedAdress = $this->createMock(Adress::class);

        $adressService->expects($this->once())
            ->method('createAdress')
            ->with($inputDTO, $user)
            ->willReturn($expectedAdress);

        $useCase = new CreateAdressUseCase($adressService);

        // Act
        $result = $useCase->execute($inputDTO, $user);

        // Assert
        $this->assertSame($expectedAdress, $result);
    }
}
