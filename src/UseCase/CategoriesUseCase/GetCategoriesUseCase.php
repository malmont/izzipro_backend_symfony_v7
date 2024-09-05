<?php
namespace App\UseCase\CategoriesUseCase;

use App\Services\CategoryService\CategoryService;

class GetCategoriesUseCase
{
    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function execute(): array
    {
        return $this->categoryService->getAllCategories();
    }
}
