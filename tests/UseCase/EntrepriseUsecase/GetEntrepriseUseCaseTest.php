<?php

namespace App\Tests\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\EntrepriseService\EntrepriseService;
use App\UseCase\EntrepriseUsecase\GetEntrepriseUseCase;
use PHPUnit\Framework\TestCase;

class GetEntrepriseUseCaseTest extends TestCase
{
    public function testExecuteReturnsDtoWhenEntrepriseFound()
    {
        $service = $this->createMock(EntrepriseService::class);
        $dto = new EntrepriseDto();
        $dto->id = 123;

        $service->expects($this->once())
            ->method('getEntrepriseByIdAndLocale')
            ->with(123, 'localhost', 'fr')
            ->willReturn($dto);

        $useCase = new GetEntrepriseUseCase($service);
        $result = $useCase->execute(123, 'localhost', 'fr');

        $this->assertSame($dto, $result);
    }

    public function testExecuteReturnsNullWhenNotFound()
    {
        $service = $this->createMock(EntrepriseService::class);

        $service->expects($this->once())
            ->method('getEntrepriseByIdAndLocale')
            ->with(999, 'localhost', 'en')
            ->willReturn(null);

        $useCase = new GetEntrepriseUseCase($service);
        $result = $useCase->execute(999, 'localhost', 'en');

        $this->assertNull($result);
    }
}
