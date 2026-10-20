<?php

namespace App\UseCase\AppProfileUseCase;

use App\Services\AppProfileService\AppProfileService;

/** GET /api/app-profile/{app} : profil public d'une application du site, repli sur la fiche entreprise */
class GetAppProfileUseCase
{
    public function __construct(private readonly AppProfileService $profiles)
    {
    }

    /** @return array<string, mixed>|null null : code d'application inconnu */
    public function execute(string $app, string $host, string $locale): ?array
    {
        $app = strtolower(trim($app));

        return AppProfileService::isKnown($app) ? $this->profiles->resolve($app, $host, $locale) : null;
    }
}
