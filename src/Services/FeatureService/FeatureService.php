<?php
namespace App\Services\FeatureService;

use App\Entity\Feature;
use App\Services\TenantEntityManagerProvider;

class FeatureService
{
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    /**
     * @return Feature[]
     */
    public function getAllFeatures(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Feature::class)->findAll();
    }
}