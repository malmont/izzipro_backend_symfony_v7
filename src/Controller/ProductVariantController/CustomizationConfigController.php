<?php

namespace App\Controller\ProductVariantController;

use App\UseCase\ProductVariantsUseCase\GetVariantCustomizationConfigUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/customization')]
class CustomizationConfigController extends AbstractController
{
    private GetVariantCustomizationConfigUseCase $useCase;

    public function __construct(GetVariantCustomizationConfigUseCase $useCase)
    {
        $this->useCase = $useCase;
    }

    #[Route('/config/{variantId}', name: 'api_customization_get_config', methods: ['GET'])]
    public function __invoke(int $variantId): JsonResponse
    {
        $configDto = $this->useCase->execute($variantId);
        return $this->json($configDto);
    }
}