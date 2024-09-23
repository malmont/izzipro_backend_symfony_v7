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

    public function execute(string $host): array
    {
        $homeSliders = $this->homeSliderService->getAllhomeSliders();
        return array_map(fn($homeSlider) => HomeSliderDTO::fromEntity($homeSlider, $host), $homeSliders);
    }
}