<?php
namespace App\Services\MultilienService;

use App\Dto\MultilienInputDto;
use App\Entity\Multilien;
use App\Services\TenantEntityManagerProvider;

class MultilienService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllMultiliens(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Multilien::class)->findAll();
    }

    public function findMultilien(int $id): ?Multilien
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Multilien::class)->find($id);
    }

    public function createMultilien(MultilienInputDto $dto): Multilien
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $multilien = new Multilien();
        $multilien->setTitre($dto->titre);
        $multilien->setLien($dto->lien);
        $multilien->setImageDeFond($dto->imageDeFond);
        
        $tenantEm->persist($multilien);
        $tenantEm->flush();

        return $multilien;
    }

    public function updateMultilien(Multilien $multilien, MultilienInputDto $dto): Multilien
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $multilien->setTitre($dto->titre ?? $multilien->getTitre());
        $multilien->setLien($dto->lien ?? $multilien->getLien());
        $multilien->setImageDeFond($dto->imageDeFond ?? $multilien->getImageDeFond());
        
        $tenantEm->flush();

        return $multilien;
    }

    public function deleteMultilien(Multilien $multilien): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($multilien);
        $tenantEm->flush();
    }
}