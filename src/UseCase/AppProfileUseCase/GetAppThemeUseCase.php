<?php

namespace App\UseCase\AppProfileUseCase;

use App\Services\AppProfileService\AppProfileService;
use App\Services\AppProfileService\AppThemeService;

/** GET /api/mobile/app-theme : thème d'une application du site (profil, puis charte de la boutique, puis null) */
class GetAppThemeUseCase
{
    public function __construct(private readonly AppThemeService $themes)
    {
    }

    /** @return array<string, mixed>|null null : code d'application inconnu */
    public function execute(string $app): ?array
    {
        $app = strtolower(trim($app));

        return AppProfileService::isKnown($app) ? $this->themes->resolve($app) : null;
    }
}
