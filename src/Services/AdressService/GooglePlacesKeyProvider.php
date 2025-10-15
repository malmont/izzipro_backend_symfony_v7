<?php
namespace App\Services\AdressService;

use App\Entity\GooglePlacesConfig;
use App\Services\TenantEntityManagerProvider;

class GooglePlacesKeyProvider
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getKey(): string
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(GooglePlacesConfig::class);
        $cfg = $repo->findOneBy([]);
        if (!$cfg) {
            throw new \LogicException('GooglePlacesConfig manquant pour ce tenant.');
        }
        if ($_ENV['APP_ENV'] === 'prod') {
            return $cfg->getGoogleApiKeyProd() ?? '';
        }
        return $cfg->getGoogleApiKeyTest() ?? '';
    }
}