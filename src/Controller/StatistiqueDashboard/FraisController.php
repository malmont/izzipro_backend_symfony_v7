<?php

namespace App\Controller\StatistiqueDashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\FraisUseCase\GetTotalFraisUseCase;

class FraisController extends AbstractController
{
    private $getTotalFraisUseCase;

    public function __construct(GetTotalFraisUseCase $getTotalFraisUseCase)
    {
        $this->getTotalFraisUseCase = $getTotalFraisUseCase;
    }

    /**
     * @Route("/api/frais/total", name="frais_total", methods={"GET"})
     */
    public function getTotalFrais(): JsonResponse
    {
        $data = $this->getTotalFraisUseCase->execute();
        return $this->json($data);
    }
}
