<?php
namespace App\UseCase\FeatureUseCase;

use App\DTO\FeatureDTO;
use App\Services\FeatureService\FeatureService;

class GetFeaturesUseCase
{
    public function __construct(private FeatureService $service) {}

    /**
     * @param string $host  Le scheme+host, ex. "https://mon-domaine.com"
     * @return FeatureDTO[]
     */
    public function execute(string $host): array
    {
        return $this->service->getAllFeatures($host);
    }
}
