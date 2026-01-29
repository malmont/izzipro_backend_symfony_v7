<?php

namespace App\Tests\UseCase\CategoriesUseCase;

use App\Services\CategoryService\CategoryService;
use App\UseCase\CategoriesUseCase\GetProductsByCategoryUseCase;
use PHPUnit\Framework\TestCase;

class GetProductsByCategoryUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {
        // Arrange
        $categoryService = $this->createMock(CategoryService::class);
        $locale = 'fr';
        $categoryIds = [1, 2];
        $keyword = 'test';
        $page = 1;
        $pageSize = 10;
        $barcode = '123456';
        $isWeb = true;
        $isPos = false;
        $expectedResult = ['product1', 'product2'];

        $categoryService->expects($this->once())
            ->method('getProductsByCategory')
            ->with($locale, $categoryIds, $keyword, $page, $pageSize, $barcode, $isWeb, $isPos)
            ->willReturn($expectedResult);

        $useCase = new GetProductsByCategoryUseCase($categoryService);

        // Act
        $result = $useCase->execute($locale, $categoryIds, $keyword, $page, $pageSize, $barcode, $isWeb, $isPos);

        // Assert
        $this->assertEquals($expectedResult, $result);
    }
}
