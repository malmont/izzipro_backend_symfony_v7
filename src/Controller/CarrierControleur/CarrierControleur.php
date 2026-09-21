<?php

namespace App\Controller\CarrierControleur;

use App\Services\MediaUrlResolver;
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
    private ?MediaUrlResolver $mediaUrlResolver;

    public function __construct(
        GetAllCarriersUseCase $getAllCarriersUseCase,
        TenantCacheService $cache,
        ?MediaUrlResolver $mediaUrlResolver = null
    )
    {
        $this->getAllCarriersUseCase = $getAllCarriersUseCase;
        $this->cache = $cache;
        $this->mediaUrlResolver = $mediaUrlResolver;
    }

    #[Route('/api/Carrier', name: 'get_carrier', methods: ['GET'])]
    public function getCarrier(Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
        $locale = $request->get('locale', 'fr');
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