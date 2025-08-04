<?php

namespace App\Controller\LandingPageSettingsController;

use App\Dto\LandingPageSettingsInputDto;
use App\UseCase\LandingPageSettingsUseCase\GetLandingPageSettingsUseCase;
use App\UseCase\LandingPageSettingsUseCase\UpdateLandingPageSettingsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/landingpage-settings')]
class LandingPageSettingsController extends AbstractController
{
    public function __construct(
        private GetLandingPageSettingsUseCase $getUseCase,
        private UpdateLandingPageSettingsUseCase $updateUseCase
    ) {
    }

    #[Route('', name: 'api_landingpage_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $setting = $this->getUseCase->execute();
        if (empty($setting->getConfiguration())) {
            return $this->json(null, Response::HTTP_OK);
        }

        return $this->json($setting->getConfiguration());
    }

    #[Route('', name: 'api_landingpage_settings_update', methods: ['PUT'])]
    public function updateSettings(#[MapRequestPayload] LandingPageSettingsInputDto $dto): JsonResponse
    {
        $this->updateUseCase->execute($dto);

        return $this->json(['status' => 'Configuration sauvegardée']);
    }
}
