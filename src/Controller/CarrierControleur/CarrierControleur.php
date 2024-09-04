<?php
namespace App\Controller\CarrierControleur;

use App\UseCase\CarrierUseCase\GetAllCarriersUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CarrierControleur extends AbstractController
{
    private GetAllCarriersUseCase $getAllCarriersUseCase;

    public function __construct(GetAllCarriersUseCase $getAllCarriersUseCase)
    {
        $this->getAllCarriersUseCase = $getAllCarriersUseCase;
    }

    #[Route('/api/Carrier', name: 'get_Carrier', methods: ['GET'])]
    public function getCarrier(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost() . '/jeesign';
        $carriers = $this->getAllCarriersUseCase->execute($host);

        return $this->json($carriers, JsonResponse::HTTP_OK);
    }
}
