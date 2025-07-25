<?php
namespace App\Services\BanniereService;

use App\Dto\BanniereInputDto;
use App\Entity\Banniere;
use App\Services\TenantEntityManagerProvider;

class BanniereService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllBannieres(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Banniere::class)->findAll();
    }

    public function findBanniere(int $id): ?Banniere
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Banniere::class)->find($id);
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