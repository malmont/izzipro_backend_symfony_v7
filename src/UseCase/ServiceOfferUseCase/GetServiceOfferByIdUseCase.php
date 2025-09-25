<?php
namespace App\UseCase\ServiceOfferUseCase;

use App\Dto\ServiceOfferOutputDto;
use App\Entity\ServiceOffer;
use App\Services\ServiceOfferService\ServiceOfferService; 

class GetServiceOfferByIdUseCase
{
    public function __construct(
        private ServiceOfferService $serviceOfferService 
    ) {}

    public function execute(int $id, string $baseImageUrl, string $locale): ?ServiceOfferOutputDto
    {
        $entity = $this->serviceOfferService->findServiceOffer($id);

        if (!$entity) {
            return null;
        }

        return new ServiceOfferOutputDto($entity, $baseImageUrl, $locale);
    }
}