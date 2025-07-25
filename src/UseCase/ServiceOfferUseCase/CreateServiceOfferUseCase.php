<?php
namespace App\UseCase\ServiceOfferUseCase;

use App\Dto\ServiceOfferInputDto;
use App\Entity\ServiceOffer;
use App\Services\ServiceOfferService\ServiceOfferService;

class CreateServiceOfferUseCase
{
    private ServiceOfferService $serviceOfferService;
    public function __construct(ServiceOfferService $serviceOfferService) { $this->serviceOfferService = $serviceOfferService; }
    public function execute(ServiceOfferInputDto $dto): ServiceOffer { return $this->serviceOfferService->createServiceOffer($dto); }
}