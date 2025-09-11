<?php
namespace App\UseCase\ServiceOfferUseCase;

use App\Dto\ServiceOfferOutputDto;
use App\Services\ServiceOfferService\ServiceOfferService;

class GetAllServiceOffersUseCase
{
    private ServiceOfferService $serviceOfferService;
    public function __construct(ServiceOfferService $serviceOfferService) { $this->serviceOfferService = $serviceOfferService; }
    
    /**
     * @return ServiceOfferOutputDto[]
     */
    public function execute(string $baseImageUrl, string $locale): array 
    { 
        $serviceOffers = $this->serviceOfferService->getAllServiceOffers();
        
        return array_map(
            fn($offer) => new ServiceOfferOutputDto($offer, $baseImageUrl, $locale),
            $serviceOffers
        );
    }
}