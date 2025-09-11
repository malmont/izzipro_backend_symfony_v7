<?php
namespace App\UseCase\FeatureUseCase;

use App\Dto\FeatureDTO;
use App\Services\FeatureService\FeatureService;

class GetFeaturesUseCase
{
    public function __construct(private FeatureService $service) {}

    /**
     * @param string $host    Le scheme+host, ex. "https://mon-domaine.com"
     * @param string $locale  La langue demandée, ex. "fr"
     * @return FeatureDTO[]
     */
    public function execute(string $host, string $locale): array
    {
        $features = $this->service->getAllFeatures();
        return array_map(
            fn($feature) => FeatureDTO::fromEntity($feature, $host, $locale),
            $features
        );
    }
}