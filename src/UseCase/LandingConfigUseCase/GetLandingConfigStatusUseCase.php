<?php

namespace App\UseCase\LandingConfigUseCase;

use App\Repository\LandingConfigSyncRepository;
use App\Services\LandingConfigService\FrontendConfigFetcher;
use App\Services\LandingConfigService\LandingConfigException;
use App\Services\LandingConfigService\LandingConfigStore;

/**
 * GET /api/landingpage-config/status : version active, version publiée par le frontend, historique.
 */
class GetLandingConfigStatusUseCase
{
    public function __construct(
        private readonly LandingConfigStore $store,
        private readonly FrontendConfigFetcher $fetcher,
        private readonly LandingConfigSyncRepository $history
    ) {
    }

    public function execute(): array
    {
        $active = $this->store->describe($this->store->activeId());
        $available = null;
        $availableError = null;
        try {
            $available = $this->fetcher->manifest();
        } catch (LandingConfigException $e) {
            $availableError = $e->getMessage();
        }
        $previous = $this->store->previousId();

        return [
            'active' => $active,
            'available' => $available,
            'availableError' => $availableError,
            'upToDate' => $available !== null && $available['version'] === $active['version'] && $available['files'] == $active['files'],
            'previous' => $previous !== null ? $this->store->describe($previous) : null,
            'history' => $this->history->latest(),
        ];
    }
}
