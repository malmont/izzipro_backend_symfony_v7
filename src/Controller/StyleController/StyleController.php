<?php

namespace App\Controller\StyleController;

use App\UseCase\StylesUseCase\GetStylesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class StyleController extends AbstractController
{
    private GetStylesUseCase $getStylesUseCase;

    public function __construct(GetStylesUseCase $getStylesUseCase)
    {
        $this->getStylesUseCase = $getStylesUseCase;
    }

    #[Route('/api/styles', name: 'get_styles', methods: ['GET'])]
    public function getStyles(): JsonResponse
    {
        $stylesArray = $this->getStylesUseCase->execute();
        return new JsonResponse($stylesArray, JsonResponse::HTTP_OK);
    }
}
