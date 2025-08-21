<?php
namespace App\Services\BaniereStatiqueService;

use App\Dto\BaniereStatiqueInputDto;
use App\Entity\BaniereStatique;
use App\Services\TenantEntityManagerProvider;

class BaniereStatiqueService
{
    public function __construct(private TenantEntityManagerProvider $emProvider) {}

    public function findAll(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(BaniereStatique::class)->findAll();
    }

    public function findById(int $id): ?BaniereStatique
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(BaniereStatique::class)->find($id);
    }

    public function create(BaniereStatiqueInputDto $dto): BaniereStatique
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $entity = new BaniereStatique();
        $entity->setTitre($dto->titre);
        $entity->setTexte($dto->texte);
        $entity->setImageDeFond($dto->imageDeFond);
        
        $tenantEm->persist($entity);
        $tenantEm->flush();
        return $entity;
    }
    
    // Ajoutez update() et delete() ici si nécessaire
}
