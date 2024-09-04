<?php
namespace App\UseCase\ColorUseCase;

use App\Services\ColorService\ColorService;

class GetAllColorsUseCase
{
    private ColorService $colorService;

    public function __construct(ColorService $colorService)
    {
        $this->colorService = $colorService;
    }

    public function execute(): array
    {
        return $this->colorService->getAllColors();
    }
}
