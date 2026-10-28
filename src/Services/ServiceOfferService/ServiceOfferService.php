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
        $serviceOffer->setPhotoService($dto->photoService);
        $serviceOffer->setTitreCommentaire($dto->titreCommentaire);
        $serviceOffer->setDescriptions($dto->descriptions);
        $this->applySettings($serviceOffer, $dto);
        
        $tenantEm->persist($serviceOffer);
        $tenantEm->flush();

        return $serviceOffer;
    }

    public function updateServiceOffer(ServiceOffer $serviceOffer, ServiceOfferInputDto $dto): ServiceOffer
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $serviceOffer->setTitre($dto->titre ?? $serviceOffer->getTitre());
        $serviceOffer->setLogo($dto->logo ?? $serviceOffer->getLogo());
        $serviceOffer->setPhotoService($dto->photoService ?? $serviceOffer->getPhotoService());
        $serviceOffer->setTitreCommentaire($dto->titreCommentaire ?? $serviceOffer->getTitreCommentaire());
        $serviceOffer->setDescriptions($dto->descriptions ?? $serviceOffer->getDescriptions());
        $this->applySettings($serviceOffer, $dto);
        
        $tenantEm->flush();

        return $serviceOffer;
    }

    /**
     * Famille, ordre, réservable, durée (28/10/2026) : null = inchangé ; familyId 0 retire la famille.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException famille inconnue
     */
    private function applySettings(ServiceOffer $serviceOffer, ServiceOfferInputDto $dto): void
    {
        if ($dto->familyId !== null) {
            $family = $dto->familyId === 0 ? null : $this->emProvider->getEntityManager()->getRepository(\App\Entity\ServiceFamily::class)->find($dto->familyId);
            if ($dto->familyId !== 0 && $family === null) {
                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('familyId : famille introuvable sur ce site.');
            }
            $serviceOffer->setFamily($family);
        }
        if ($dto->position !== null) {
            $serviceOffer->setPosition($dto->position);
        }
        if ($dto->bookable !== null) {
            $serviceOffer->setBookable($dto->bookable);
        }
        if ($dto->durationMinutes !== null) {
            $serviceOffer->setDurationMinutes($dto->durationMinutes);
        }
    }

    public function deleteServiceOffer(ServiceOffer $serviceOffer): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($serviceOffer);
        $tenantEm->flush();
    }
}