<?php
namespace App\Controller\SizesController;

use App\UseCase\SizesUseCase\GetSizesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SizeController extends AbstractController
{
    private GetSizesUseCase $getSizesUseCase;

    public function __construct(GetSizesUseCase $getSizesUseCase)
    {
        $this->getSizesUseCase = $getSizesUseCase;
    }

    #[Route('/api/sizes', name: 'get_sizes', methods: ['GET'])]
    public function getSizes(): JsonResponse
    {
        $sizesArray = $this->getSizesUseCase->execute();
        return new JsonResponse($sizesArray, JsonResponse::HTTP_OK);
    }
}
