<?php

namespace App\Tests\UseCase\CategoriesUseCase;

use App\Services\CategoryService\CategoryService;
use App\UseCase\CategoriesUseCase\GetCategoriesUseCase;
use PHPUnit\Framework\TestCase;

class GetCategoriesUseCaseTest extends TestCase
{
    public function testExecuteDelegatesToService()
    {

        $categoryService = $this->createMock(CategoryService::class);
        $locale = 'fr';
        $expectedCategories = ['cat1', 'cat2'];

        $categoryService->expects($this->once())
            ->method('getAllCategories')
            ->with($locale)
            ->willReturn($expectedCategories);

        $useCase = new GetCategoriesUseCase($categoryService);

        $result = $useCase->execute($locale);

        $this->assertEquals($expectedCategories, $result);
    }
}
