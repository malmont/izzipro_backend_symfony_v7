<?php
namespace App\UseCase\ServiceOfferUseCase;

use App\Dto\ServiceOfferInputDto;
use App\Entity\ServiceOffer;
use App\Services\ServiceOfferService\ServiceOfferService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateServiceOfferUseCase
{
    private ServiceOfferService $serviceOfferService;
    public function __construct(ServiceOfferService $serviceOfferService) { $this->serviceOfferService = $serviceOfferService; }
    public function execute(int $id, ServiceOfferInputDto $dto): ServiceOffer
    {
        $serviceOffer = $this->serviceOfferService->findServiceOffer($id);
        if (!$serviceOffer) { throw new NotFoundHttpException('Offre de service non trouvée.'); }
        return $this->serviceOfferService->updateServiceOffer($serviceOffer, $dto);
    }
}
