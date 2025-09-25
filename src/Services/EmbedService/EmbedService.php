<?php
namespace App\Services\EmbedService;

use App\Entity\Embed;
use App\Services\TenantEntityManagerProvider;

class EmbedService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function findAllEmbeds(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Embed::class)->findAll();
    }

    public function findEmbedById(int $id): ?Embed
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Embed::class)->find($id);
    }
}
