<?php

namespace App\Controller\HomeSliderController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\GetAllHomeSliderUseCase\GetAllHomeSliderUseCase;

class HomeSliderController extends AbstractController
{
    private GetAllHomeSliderUseCase $getAllHomeSliderUseCase;

    public function __construct(GetAllHomeSliderUseCase $getAllHomeSliderUseCase)
    {
        $this->getAllHomeSliderUseCase = $getAllHomeSliderUseCase;
    }

    #[Route('/api/homeslider', name: 'get_home_slider', methods: ['GET'])]
    public function getHomeSLider(Request $request)
    {
        $host = $request->getSchemeAndHttpHost();
        $homeSlider = $this->getAllHomeSliderUseCase->execute($host);
        return $this->json($homeSlider, JsonResponse::HTTP_OK);
    }

}