<?php
namespace App\Controller\ColorsController;

use App\Dto\ColorOutputDTO;
use App\UseCase\ColorUseCase\GetAllColorsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ColorController extends AbstractController
{
    private GetAllColorsUseCase $getAllColorsUseCase;

    public function __construct(GetAllColorsUseCase $getAllColorsUseCase)
    {
        $this->getAllColorsUseCase = $getAllColorsUseCase;
    }

    #[Route('/api/colors', name: 'get_colors', methods: ['GET'])]
    public function getColors(): JsonResponse
    {
        $colors = $this->getAllColorsUseCase->execute();
        $colorsArray = array_map(fn($color) => new ColorOutputDTO($color), $colors);

        return $this->json($colorsArray, JsonResponse::HTTP_OK);
    }
}
