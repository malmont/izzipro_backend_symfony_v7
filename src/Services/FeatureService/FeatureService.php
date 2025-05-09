<?php
namespace App\Services\FeatureService;

use App\Dto\FeatureDTO;
use App\Repository\FeatureRepository;

class FeatureService
{
    public function __construct(private FeatureRepository $repo) {}

    /**
     * @param string $host  ex. "https://mon-domaine.com"
     * @return FeatureDTO[]
     */
    public function getAllFeatures(string $host): array
    {
        $features = $this->repo->findAll();
        $dtos = [];

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
