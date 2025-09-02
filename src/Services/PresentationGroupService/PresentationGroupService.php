<?php
namespace App\Services\PresentationGroupService;

use App\Entity\PresentationGroup;
use App\Services\TenantEntityManagerProvider;

class PresentationGroupService
{
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    public function findAll(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(PresentationGroup::class)->findAll();
    }

    public function findById(int $id): ?PresentationGroup
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(PresentationGroup::class)->find($id);
    }
    
    // Vous pouvez ajouter create, update, delete ici si nécessaire
}
