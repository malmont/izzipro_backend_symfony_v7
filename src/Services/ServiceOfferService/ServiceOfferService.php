<?php
namespace App\Services\ServiceOfferService;

use App\Dto\ServiceOfferInputDto;
use App\Entity\ServiceOffer;
use App\Services\TenantEntityManagerProvider;

class ServiceOfferService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllServiceOffers(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(ServiceOffer::class)->findAll();
    }

    public function findServiceOffer(int $id): ?ServiceOffer
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(ServiceOffer::class)->find($id);
    }

    public function createServiceOffer(ServiceOfferInputDto $dto): ServiceOffer
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $serviceOffer = new ServiceOffer();
        $serviceOffer->setTitre($dto->titre);
        $serviceOffer->setLogo($dto->logo);
        $serviceOffer->setTitreCommentaire($dto->titreCommentaire);
        $serviceOffer->setDescriptions($dto->descriptions);
        
        $tenantEm->persist($serviceOffer);
        $tenantEm->flush();

        return $serviceOffer;
    }

    public function updateServiceOffer(ServiceOffer $serviceOffer, ServiceOfferInputDto $dto): ServiceOffer
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $serviceOffer->setTitre($dto->titre ?? $serviceOffer->getTitre());
        $serviceOffer->setLogo($dto->logo ?? $serviceOffer->getLogo());
        $serviceOffer->setTitreCommentaire($dto->titreCommentaire ?? $serviceOffer->getTitreCommentaire());
        $serviceOffer->setDescriptions($dto->descriptions ?? $serviceOffer->getDescriptions());
        
        $tenantEm->flush();

        return $serviceOffer;
    }

    public function deleteServiceOffer(ServiceOffer $serviceOffer): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($serviceOffer);
        $tenantEm->flush();
    }
}