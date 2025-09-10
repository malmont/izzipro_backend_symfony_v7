<?php

namespace App\Controller\CarrierControleur;

use App\UseCase\CarrierUseCase\GetAllCarriersUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class CarrierControleur extends AbstractController
{
    private GetAllCarriersUseCase $getAllCarriersUseCase;
    private TenantCacheService $cache;

    public function __construct(GetAllCarriersUseCase $getAllCarriersUseCase, TenantCacheService $cache)
    {
        $this->getAllCarriersUseCase = $getAllCarriersUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/Carrier', name: 'get_carrier', methods: ['GET'])]
    public function getCarrier(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $locale = $request->getLocale();
        $cacheKey = 'carriers_' . $locale;

        $carriers = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($host, $locale) {
                $item->expiresAfter(3600);
                $item->tag(['carriers_all']);
                error_log("Cache miss for carriers in locale: " . $locale);
                return $this->getAllCarriersUseCase->execute($host, $locale);
            }
        );

        return $this->json($carriers, JsonResponse::HTTP_OK);
    }
}