<?php

namespace App\Controller\HomeSliderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\GetAllHomeSliderUseCase\GetAllHomeSliderUseCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class HomeSliderController extends AbstractController
{
    private GetAllHomeSliderUseCase $getAllHomeSliderUseCase;
    private CacheInterface $cache;

    public function __construct(
        GetAllHomeSliderUseCase $getAllHomeSliderUseCase,
        CacheInterface $cache
    ) {
        $this->getAllHomeSliderUseCase = $getAllHomeSliderUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/homeslider', name: 'get_home_slider', methods: ['GET'])]
    public function getHomeSlider(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();

        $homeSlider = $this->cache->get('homeslider', function (ItemInterface $item) use ($host) {
            $item->expiresAfter(3600);
            error_log("Cache miss for homeslider");
            return $this->getAllHomeSliderUseCase->execute($host);
        });
        

        return $this->json($homeSlider, JsonResponse::HTTP_OK);
    }
}
