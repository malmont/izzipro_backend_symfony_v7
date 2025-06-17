<?php
namespace App\Services\AdressService;

use App\Entity\GooglePlacesConfig; // <-- On importe l'entité
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class GooglePlacesKeyProvider
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getKey(): string
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(GooglePlacesConfig::class);

        // On utilise le repository obtenu depuis l'EM du tenant
        $cfg = $repo->findOneBy([]);
        if (!$cfg) {
            throw new \LogicException('GooglePlacesConfig manquant pour ce tenant.');
        }
        
        // Le reste de votre logique est inchangée
        if ($_ENV['APP_ENV'] === 'prod') {
            return $cfg->getGoogleApiKeyProd() ?? '';
        }
        return $cfg->getGoogleApiKeyTest() ?? '';
    }
}