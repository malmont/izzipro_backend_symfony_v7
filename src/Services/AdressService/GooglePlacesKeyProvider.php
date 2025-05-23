<?php
namespace App\Services\AdressService;

use App\Repository\GooglePlacesConfigRepository;

class GooglePlacesKeyProvider
{
    public function __construct(private GooglePlacesConfigRepository $repo) {}

    public function getKey(): string
    {
        $cfg = $this->repo->findOneBy([]);
        if (!$cfg) {
            throw new \LogicException('GooglePlacesConfig manquant.');
        }
        if ($_ENV['APP_ENV'] === 'prod') {
            return $cfg->getGoogleApiKeyProd() ?? '';
        }
        return $cfg->getGoogleApiKeyTest() ?? '';
    }
}
