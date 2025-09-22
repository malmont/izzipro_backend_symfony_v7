<?php
namespace App\Services\BanniereService;

use App\Dto\BanniereInputDto;
use App\Entity\Banniere;
use App\Services\TenantEntityManagerProvider;

class BanniereService
{


    public function __construct(private TenantEntityManagerProvider $emProvider)
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $this->repository = $tenantEm->getRepository(Banniere::class);
    }

    public function findAllByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }


    public function findByIdAndLocale(int $id, string $locale): ?Banniere
    {
        return $this->repository->findByIdAndLocale($id, $locale);
    }

    public function createBanniere(BanniereInputDto $dto): Banniere
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $banniere = new Banniere();
        $banniere->setTitre($dto->titre);
        $banniere->setTexte($dto->texte);
        
        $tenantEm->persist($banniere);
        $tenantEm->flush();

        return $banniere;
    }

    public function updateBanniere(Banniere $banniere, BanniereInputDto $dto): Banniere
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $banniere->setTitre($dto->titre ?? $banniere->getTitre());
        $banniere->setTexte($dto->texte ?? $banniere->getTexte());
        
        $tenantEm->flush();

        return $banniere;
    }

    public function deleteBanniere(Banniere $banniere): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($banniere);
        $tenantEm->flush();
    }
}