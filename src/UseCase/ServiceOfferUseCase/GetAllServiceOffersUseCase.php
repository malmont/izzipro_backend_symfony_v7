<?php
namespace App\UseCase\ServiceOfferUseCase;

use App\Services\ServiceOfferService\ServiceOfferService;

class GetAllServiceOffersUseCase
{
    private ServiceOfferService $serviceOfferService;
    public function __construct(ServiceOfferService $serviceOfferService) { $this->serviceOfferService = $serviceOfferService; }
    public function execute(): array { return $this->serviceOfferService->getAllServiceOffers(); }
}