<?php
namespace App\Services\EmploiService;

use App\Dto\EmploiInputDto;
use App\Entity\Emploi;
use App\Services\TenantEntityManagerProvider;

class EmploiService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllEmplois(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Emploi::class)->findAll();
    }

    public function findEmploi(int $id): ?Emploi
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Emploi::class)->find($id);
    }

    public function createEmploi(EmploiInputDto $dto): Emploi
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $emploi = new Emploi();
        $emploi->setTitre($dto->titre);
        $emploi->setDescription($dto->description);
        
        $tenantEm->persist($emploi);
        $tenantEm->flush();

        return $emploi;
    }

    public function updateEmploi(Emploi $emploi, EmploiInputDto $dto): Emploi
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $emploi->setTitre($dto->titre ?? $emploi->getTitre());
        $emploi->setDescription($dto->description ?? $emploi->getDescription());
        
        $tenantEm->flush();

        return $emploi;
    }

    public function deleteEmploi(Emploi $emploi): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($emploi);
        $tenantEm->flush();
    }
}
