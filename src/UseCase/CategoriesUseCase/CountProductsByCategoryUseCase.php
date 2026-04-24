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

    public function execute(string $locale, ?array $categoryIds, ?string $keyword = null): int
    {
        return $this->categoryService->countTotalProducts($locale, $categoryIds, $keyword);
    }
}
