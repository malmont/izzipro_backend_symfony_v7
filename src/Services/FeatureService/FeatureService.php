<?php
namespace App\Services\FeatureService;

use App\Dto\FeatureDTO;
use App\Entity\Feature; // <-- On importe l'entité
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class FeatureService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    /**
     * @param string $host  ex. "https://mon-domaine.com"
     * @return FeatureDTO[]
     */
    public function getAllFeatures(string $host): array
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(Feature::class);

        // On utilise le repository obtenu depuis l'EM du tenant
        $features = $repo->findAll();
        $dtos = [];

        // Le reste de votre logique de création de DTO est inchangée et parfaite.
        foreach ($features as $f) {
            $path = $f->getIconPath();  
            $url  = $path
                ? rtrim($host, '/') . '/assets/uploads/icons/' . $path
                : null;

            $dtos[] = new FeatureDTO($f->getId(), $f->getTitle(), $url);
        }

        return $dtos;
    }
}