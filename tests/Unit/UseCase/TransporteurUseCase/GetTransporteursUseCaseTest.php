<?php

namespace App\Tests\Unit\UseCase\TransporteurUseCase;

use App\Entity\Transporteur;
use App\Services\TransporteurService\TransporteurService;
use App\UseCase\TransporteurUseCase\GetTransporteursUseCase;
use PHPUnit\Framework\TestCase;

class GetTransporteursUseCaseTest extends TestCase
{
    public function testExecute(): void
    {
        $transporteurService = $this->createMock(TransporteurService::class);
        $useCase = new GetTransporteursUseCase($transporteurService);

        $transporteur1 = new Transporteur();
        $transporteur1->setName('Carrier 1');
        $transporteur1->setLogo('logo1.png');
        $transporteur1->setContact('contact1@test.com');

        $transporteur2 = new Transporteur();
        $transporteur2->setName('Carrier 2');
        // logo and contact null for second one to test nullable handling if needed, 
        // essentially just mapping check.

        $transporteurService->expects($this->once())
            ->method('getAllTransporteurs')
            ->willReturn([$transporteur1, $transporteur2]);

        $result = $useCase->execute();

        $this->assertCount(2, $result);

        $this->assertEquals([
            'id' => null,
            'name' => 'Carrier 1',
            'logo' => 'logo1.png',
            'contact' => 'contact1@test.com'
        ], $result[0]);

        $this->assertEquals([
            'id' => null,
            'name' => 'Carrier 2',
            'logo' => null,
            'contact' => null
        ], $result[1]);
    }
}
