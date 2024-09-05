<?php
namespace App\UseCase\CategoriesUseCase;

use App\Services\CategoryService\CategoryService;

class GetProductsByCategoryUseCase
{
    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function execute(?array $categoryIds, int $page, int $pageSize): array
    {
        return $this->categoryService->getProductsByCategory($categoryIds, $page, $pageSize);
    }
}
