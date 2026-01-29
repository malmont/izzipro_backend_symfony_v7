<?php

namespace App\Tests\Unit\UseCase\TransporteurUseCase;

use App\Entity\Transporteur;
use App\Services\TransporteurService\TransporteurService;
use App\UseCase\TransporteurUseCase\DeleteTransporteurUseCase;
use PHPUnit\Framework\TestCase;

class DeleteTransporteurUseCaseTest extends TestCase
{
    public function testExecute(): void
    {
        $transporteurService = $this->createMock(TransporteurService::class);
        $useCase = new DeleteTransporteurUseCase($transporteurService);

        $transporteur = new Transporteur();

        $transporteurService->expects($this->once())
            ->method('deleteTransporteur')
            ->with($transporteur);

        $useCase->execute($transporteur);
    }
}
