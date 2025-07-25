<?php
namespace App\Services\MarqueService;

use App\Dto\MarqueInputDto;
use App\Entity\CategorieMarque;
use App\Entity\Marque;
use App\Services\TenantEntityManagerProvider;
use Doctrine\Common\Collections\ArrayCollection;

class MarqueService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllMarques(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Marque::class)->findAll();
    }

    public function findMarque(int $id): ?Marque
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Marque::class)->find($id);
    }

    public function createMarque(MarqueInputDto $dto): Marque
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $marque = new Marque();
        $marque->setTitre($dto->titre);
        $marque->setLogosMarques($dto->logosMarques);

        $this->syncCategories($marque, $dto->categoryIds);
        
        $tenantEm->persist($marque);
        $tenantEm->flush();

        return $marque;
    }

    public function updateMarque(Marque $marque, MarqueInputDto $dto): Marque
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $marque->setTitre($dto->titre ?? $marque->getTitre());
        $marque->setLogosMarques($dto->logosMarques ?? $marque->getLogosMarques());

        $this->syncCategories($marque, $dto->categoryIds);
        
        $tenantEm->flush();

        return $marque;
    }

    public function deleteMarque(Marque $marque): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($marque);
        $tenantEm->flush();
    }

    private function syncCategories(Marque $marque, array $categoryIds): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $categoryRepo = $tenantEm->getRepository(CategorieMarque::class);

        // On vide les catégories actuelles
        $marque->getCategories()->clear();

        // On ajoute les nouvelles
        foreach ($categoryIds as $categoryId) {
            $category = $categoryRepo->find($categoryId);
            if ($category) {
                $marque->addCategory($category);
            }
        }
    }
}