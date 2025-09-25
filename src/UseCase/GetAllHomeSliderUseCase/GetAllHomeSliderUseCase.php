<?php
namespace App\UseCase\GetAllHomeSliderUseCase;

use App\Services\HomeSliderService\HomeSliderService;
use App\Dto\HomeSliderDTO;

class GetAllHomeSliderUseCase
{
    private HomeSliderService $homeSliderService;
    public function __construct(HomeSliderService $homeSliderService)
    {
        $this->homeSliderService = $homeSliderService;
    }

    public function execute(string $host, string $locale): array
    {
        $homeSliders = $this->homeSliderService->getAllHomeSlidersByLocale($locale);
        
        return array_map(
            fn($homeSlider) => HomeSliderDTO::fromEntity($homeSlider, $host, $locale), 
            $homeSliders
        );
    }
}