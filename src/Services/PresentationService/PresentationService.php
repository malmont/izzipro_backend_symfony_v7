<?php
namespace App\Services\PresentationService;

use App\Entity\Presentation;
use App\Services\TenantEntityManagerProvider;

class PresentationService
{
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    public function findAll(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Presentation::class)->findAll();
    }

    public function findById(int $id): ?Presentation
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Presentation::class)->find($id);
    }
    
}
