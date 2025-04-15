<?php
namespace App\Controller\ColorsController;

use App\Dto\ColorOutputDTO;
use App\UseCase\ColorUseCase\GetAllColorsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ColorController extends AbstractController
{
    private GetAllColorsUseCase $getAllColorsUseCase;
    private CacheInterface $cache;

    public function __construct(GetAllColorsUseCase $getAllColorsUseCase, CacheInterface $cache)
    {
        $this->cache = $cache;
        $this->getAllColorsUseCase = $getAllColorsUseCase;
    }

    #[Route('/api/colors', name: 'get_colors', methods: ['GET'])]
    public function getColors(): JsonResponse
    {
        $colorsArray = $this->cache->get('colors_all', function (ItemInterface $item) {
            $item->expiresAfter(3600); // Le cache expire après 1 heure
            $colors = $this->getAllColorsUseCase->execute();
            // Transformation des entités en DTO (ici, on suppose que le DTO dispose d'une méthode toArray())
            return array_map(function ($color) {
                $dto = new ColorOutputDTO($color);
                return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
            }, $colors);
        });
        
        return new JsonResponse($colorsArray, JsonResponse::HTTP_OK);
    }
}
