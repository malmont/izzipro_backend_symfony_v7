<?php

namespace App\Tests\Unit\UseCase\TransporteurUseCase;

use App\Dto\TransporteurDTO;
use App\Entity\Transporteur;
use App\Services\TransporteurService\TransporteurService;
use App\UseCase\TransporteurUseCase\CreateTransporteurUseCase;
use PHPUnit\Framework\TestCase;

class CreateTransporteurUseCaseTest extends TestCase
{
    public function testExecute(): void
    {
        $transporteurService = $this->createMock(TransporteurService::class);
        $useCase = new CreateTransporteurUseCase($transporteurService);

        $dto = new TransporteurDTO('Test Carrier', 'logo.png', 'contact@test.com');
        $expectedTransporteur = new Transporteur();

        $transporteurService->expects($this->once())
            ->method('createTransporteur')
            ->with($dto->name, $dto->logo, $dto->contact)
            ->willReturn($expectedTransporteur);

        $result = $useCase->execute($dto);

        $this->assertSame($expectedTransporteur, $result);
    }
}
