<?php

namespace App\Controller\CarrierControleur;

use App\UseCase\CarrierUseCase\GetAllCarriersUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CarrierControleur extends AbstractController
{
    private GetAllCarriersUseCase $getAllCarriersUseCase;
    private CacheInterface $cache;

    public function __construct(GetAllCarriersUseCase $getAllCarriersUseCase, CacheInterface $cache)
    {
        $this->getAllCarriersUseCase = $getAllCarriersUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/Carrier', name: 'get_carrier', methods: ['GET'])]
    public function getCarrier(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();

        $carriers = $this->cache->get('carriers', function (ItemInterface $item) use ($host) {
            $item->expiresAfter(3600);
            error_log("Cache miss for carriers");
            return $this->getAllCarriersUseCase->execute($host);
        });

        return $this->json($carriers, JsonResponse::HTTP_OK);
    }
}
