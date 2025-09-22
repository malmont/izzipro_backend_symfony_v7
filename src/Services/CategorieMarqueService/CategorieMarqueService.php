<?php
namespace App\Services\CategorieMarqueService;

use App\Dto\CategorieMarqueInputDto;
use App\Entity\CategorieMarque;
use App\Services\TenantEntityManagerProvider;

class CategorieMarqueService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllCategoriesMarque(string $locale): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $repository = $tenantEm->getRepository(CategorieMarque::class);
        return $repository->findAllByLocale($locale);
    }

    public function findCategorieMarque(int $id, string $locale): ?CategorieMarque
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(CategorieMarque::class)->findByIdAndLocale($id,$locale);
    }

    public function createCategorieMarque(CategorieMarqueInputDto $dto): CategorieMarque
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $categorie = new CategorieMarque();
        $categorie->setNom($dto->nom);
        
        $tenantEm->persist($categorie);
        $tenantEm->flush();

        return $categorie;
    }

    public function updateCategorieMarque(CategorieMarque $categorie, CategorieMarqueInputDto $dto): CategorieMarque
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $categorie->setNom($dto->nom ?? $categorie->getNom());
        $tenantEm->flush();
        return $categorie;
    }

    public function deleteCategorieMarque(CategorieMarque $categorie): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($categorie);
        $tenantEm->flush();
    }
}