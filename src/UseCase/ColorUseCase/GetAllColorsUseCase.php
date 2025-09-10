<?php
namespace App\UseCase\ColorUseCase;

use App\Services\ColorService\ColorService;
use App\Dto\ColorOutputDTO;


class GetAllColorsUseCase
{
    private ColorService $colorService;

    public function __construct(ColorService $colorService)
    {
        $this->colorService = $colorService;
    }


    public function execute(string $locale): array
    {
        $colors = $this->colorService->getAllColors();
        
        return array_map(
            fn($color) => new ColorOutputDTO($color, $locale),
            $colors
        );
    }
}
