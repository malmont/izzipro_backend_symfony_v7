<?php

namespace App\UseCase\ServiceOfferUseCase;

use App\Entity\ServiceOffer;
use App\Services\ServiceOfferService\ServiceOfferService; 

class GetServiceOfferByIdUseCase
{
    public function __construct(
        private ServiceOfferService $serviceOfferService 
    ) {
    }

    public function execute(int $id): ?ServiceOffer
    {
        return $this->serviceOfferService->findServiceOffer($id);
    }
}
