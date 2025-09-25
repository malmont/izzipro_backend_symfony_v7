<?php
namespace App\Services\FeatureService;

use App\Entity\Feature;
use App\Repository\FeatureRepository;
use App\Services\TenantEntityManagerProvider;

class FeatureService
{
    private FeatureRepository $repository;

    public function __construct(private TenantEntityManagerProvider $emProvider) 
    {
        $em = $this->emProvider->getEntityManager();
        $this->repository = $em->getRepository(Feature::class);
    }

    public function getAllFeaturesByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }
}