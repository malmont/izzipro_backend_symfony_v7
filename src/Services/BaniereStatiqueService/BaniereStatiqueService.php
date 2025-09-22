<?php
namespace App\Services\BaniereStatiqueService;

use App\Dto\BaniereStatiqueInputDto;
use App\Entity\BaniereStatique;
use App\Services\TenantEntityManagerProvider;

class BaniereStatiqueService
{
    public function __construct(private TenantEntityManagerProvider $emProvider) {

        $tenantEm = $this->emProvider->getEntityManager();
        $this->repository = $tenantEm->getRepository(BaniereStatique::class);
    }

    public function findAll(): array
    {
        return $this->repository->findAll();
        return $tenantEm->getRepository(BaniereStatique::class)->findAll();
    }

    public function findAllByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }

    public function findByIdAndLocale(int $id, string $locale): ?BaniereStatique
    {
        return $this->repository->findByIdAndLocale($id, $locale);
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
}
