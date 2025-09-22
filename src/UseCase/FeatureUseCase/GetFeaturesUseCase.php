<?php
namespace App\UseCase\FeatureUseCase;

use App\Dto\FeatureDTO;
use App\Services\FeatureService\FeatureService;

class GetFeaturesUseCase
{
    public function __construct(private FeatureService $service) {}

    public function execute(string $host, string $locale): array
    {
        $features = $this->service->getAllFeaturesByLocale($locale);
        return array_map(
            fn($feature) => FeatureDTO::fromEntity($feature, $host, $locale),
            $features
        );
    }
}