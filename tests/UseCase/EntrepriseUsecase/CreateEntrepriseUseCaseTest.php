<?php

namespace App\Tests\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\EntrepriseService\EntrepriseService;
use App\UseCase\EntrepriseUsecase\CreateEntrepriseUseCase;
use PHPUnit\Framework\TestCase;

class CreateEntrepriseUseCaseTest extends TestCase
{
    public function testExecuteCreatesEntrepriseWithCorrectData()
    {
        // Arrange
        $entrepriseService = $this->createMock(EntrepriseService::class);
        $data = [
            'name' => 'My Company',
            'logo' => 'logo.png',
            'email' => 'contact@company.com',
            'tel' => '123456789',
            'website' => 'https://company.com',
            'ein' => 'EIN123',
            'tvaIntracommunautaire' => 'FR123456789',
            'conditionOfUse' => 'Terms...',
            'LegalNotice' => 'Notice...',
            'privacyPolicy' => 'Privacy...',
            'adress' => '123 Main St'
        ];

        $expectedDto = new EntrepriseDto();
        $expectedDto->name = 'My Company';
        $expectedDto->logo = 'logo.png';
        $expectedDto->email = 'contact@company.com';
        $expectedDto->tel = '123456789';
        $expectedDto->website = 'https://company.com';
        $expectedDto->ein = 'EIN123';
        $expectedDto->tvaIntracommunautaire = 'FR123456789';
        $expectedDto->conditionOfUse = 'Terms...';
        $expectedDto->LegalNotice = 'Notice...';
        $expectedDto->privacyPolicy = 'Privacy...';
        $expectedDto->adress = '123 Main St';

        // We expect the service to return a DTO (doesn't matter much which one, just to match return type)
        $returnedDto = clone $expectedDto;
        $returnedDto->id = 1;

        $entrepriseService->expects($this->once())
            ->method('createEntreprise')
            ->with($this->callback(function (EntrepriseDto $dto) use ($expectedDto) {
                return $dto->name === $expectedDto->name
                    && $dto->email === $expectedDto->email
                    && $dto->tel === $expectedDto->tel
                    && $dto->website === $expectedDto->website
                    && $dto->ein === $expectedDto->ein
                    && $dto->tvaIntracommunautaire === $expectedDto->tvaIntracommunautaire
                    && $dto->adress === $expectedDto->adress;
            }))
            ->willReturn($returnedDto);

        $useCase = new CreateEntrepriseUseCase($entrepriseService);

        // Act
        $result = $useCase->execute($data);

        // Assert
        $this->assertEquals(1, $result->id);
        $this->assertEquals('My Company', $result->name);
    }
}
