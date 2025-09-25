<?php
namespace App\Services\PresentationGroupService;

use App\Entity\PresentationGroup;
use App\Repository\PresentationGroupRepository;
use App\Services\TenantEntityManagerProvider;

class PresentationGroupService
{
    private PresentationGroupRepository $repository;
    
    public function __construct(private TenantEntityManagerProvider $emProvider) 
    {
        $em = $this->emProvider->getEntityManager();
        $this->repository = $em->getRepository(PresentationGroup::class);
    }

    public function findAllByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }

    public function findByIdAndLocale(int $id, string $locale): ?PresentationGroup
    {
        return $this->repository->findByIdAndLocale($id, $locale);
    }
}