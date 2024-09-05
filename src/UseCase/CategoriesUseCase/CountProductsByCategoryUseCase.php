<?php
namespace App\UseCase\CategoriesUseCase;

use App\Services\CategoryService\CategoryService;

class CountProductsByCategoryUseCase
{
    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function execute(?array $categoryIds): int
    {
        return $this->categoryService->countTotalProducts($categoryIds);
    }
}
