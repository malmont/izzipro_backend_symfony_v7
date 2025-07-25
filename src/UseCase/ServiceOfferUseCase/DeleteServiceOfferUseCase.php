<?php
namespace App\UseCase\ServiceOfferUseCase;

use App\Services\ServiceOfferService\ServiceOfferService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteServiceOfferUseCase
{
    private ServiceOfferService $serviceOfferService;
    public function __construct(ServiceOfferService $serviceOfferService) { $this->serviceOfferService = $serviceOfferService; }
    public function execute(int $id): void
    {
        $serviceOffer = $this->serviceOfferService->findServiceOffer($id);
        if (!$serviceOffer) { throw new NotFoundHttpException('Offre de service non trouvée.'); }
        $this->serviceOfferService->deleteServiceOffer($serviceOffer);
    }
}