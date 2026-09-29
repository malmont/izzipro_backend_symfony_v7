<?php

namespace App\Controller\LandingPagesController;

use App\Services\LandingPagesService\ComponentsConfigProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class LandingPagesController extends AbstractController
{
    #[Route('/api/components-config', name: 'api_components_config', methods: ['GET'])]
    public function getComponentsConfig(ComponentsConfigProvider $componentsConfig): JsonResponse
    {
        return $this->json($componentsConfig->all());
    }
}
