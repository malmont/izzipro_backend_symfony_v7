<?php

namespace App\Tests\UseCase\ColorUseCase;

use App\Dto\ColorOutputDTO;
use App\Entity\Color;
use App\Entity\ColorTranslation;
use App\Services\ColorService\ColorService;
use App\UseCase\ColorUseCase\GetAllColorsUseCase;
use PHPUnit\Framework\TestCase;

class GetAllColorsUseCaseTest extends TestCase
{
    public function testExecute()
    {
        $colorServiceMock = $this->createMock(ColorService::class);
        $colorMock = $this->createMock(Color::class);
        $translationMock = $this->createMock(ColorTranslation::class);
        $locale = 'en';

        $colorMock->method('getTranslation')
            ->with($locale)
            ->willReturn($translationMock);

        $colorMock->method('getId')->willReturn(1);
        $colorMock->method('getCode')->willReturn('CODE');
        $colorMock->method('getCodeHexa')->willReturn('#FFFFFF');
        $colorMock->method('getName')->willReturn('Default Name');

        $translationMock->method('getName')->willReturn('Translated Name');

        $colorServiceMock->method('getAllColorsByLocale')
            ->with($locale)
            ->willReturn([$colorMock]);

        $useCase = new GetAllColorsUseCase($colorServiceMock);
        $result = $useCase->execute($locale);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ColorOutputDTO::class, $result[0]);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Translated Name', $result[0]->name);
        $this->assertEquals('CODE', $result[0]->code);
        $this->assertEquals('#FFFFFF', $result[0]->codeHexa);
    }
}
