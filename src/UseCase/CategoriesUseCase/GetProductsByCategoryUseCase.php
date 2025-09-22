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

    public function execute(string $locale, ?array $categoryIds, ?string $keyword, int $page, int $pageSize, ?string $barcode, ?bool $isWeb, ?bool $isPos): array
    {
        return $this->categoryService->getProductsByCategory($locale, $categoryIds, $keyword, $page, $pageSize, $barcode, $isWeb, $isPos);
    }

}
