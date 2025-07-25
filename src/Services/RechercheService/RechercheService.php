<?php
namespace App\Services\RechercheService;

use App\Dto\RechercheInputDto;
use App\Entity\Recherche;
use App\Services\TenantEntityManagerProvider;

class RechercheService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllRecherches(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Recherche::class)->findAll();
    }

    public function findRecherche(int $id): ?Recherche
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Recherche::class)->find($id);
    }

    public function createRecherche(RechercheInputDto $dto): Recherche
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $recherche = new Recherche();
        $recherche->setTitre($dto->titre);
        $recherche->setTexte1($dto->texte1);
        $recherche->setTexte2($dto->texte2);
        $recherche->setImageDeFond($dto->imageDeFond);
        
        $tenantEm->persist($recherche);
        $tenantEm->flush();

        return $recherche;
    }

    public function updateRecherche(Recherche $recherche, RechercheInputDto $dto): Recherche
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $recherche->setTitre($dto->titre ?? $recherche->getTitre());
        $recherche->setTexte1($dto->texte1 ?? $recherche->getTexte1());
        $recherche->setTexte2($dto->texte2 ?? $recherche->getTexte2());
        $recherche->setImageDeFond($dto->imageDeFond ?? $recherche->getImageDeFond());
        
        $tenantEm->flush();

        return $recherche;
    }

    public function deleteRecherche(Recherche $recherche): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($recherche);
        $tenantEm->flush();
    }
}