<?php

namespace App\Tests\UseCase\CategoriesUseCase;

use App\Services\CategoryService\CategoryService;
use App\UseCase\CategoriesUseCase\CountProductsByCategoryUseCase;
use PHPUnit\Framework\TestCase;

class CountProductsByCategoryUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {
        $categoryService = $this->createMock(CategoryService::class);
        $locale = 'fr';
        $categoryIds = [1, 2];
        $keyword = 'test_keyword';
        $expectedCount = 42;

        $categoryService->expects($this->once())
            ->method('countTotalProducts')
            ->with($locale, $categoryIds, $keyword)
            ->willReturn($expectedCount);

        $useCase = new CountProductsByCategoryUseCase($categoryService);
        $result = $useCase->execute($locale, $categoryIds, $keyword);
        $this->assertEquals($expectedCount, $result);
    }
}
