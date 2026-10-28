<?php

namespace App\Controller\LandingPageSettingsController;

use App\Cache\CacheTags;
use App\Dto\LandingPageSettingsInputDto;
use App\Services\LandingPageSettingsService\LandingPageSettingsService;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\Services\TenantCacheService;
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
        private UpdateLandingPageSettingsUseCase $updateUseCase,
        private LandingPageSettingsService $service,
        private ReglableCompositionValidator $reglableValidator,
        private TenantCacheService $cache
    ) {
    }

    /**
     * Réglages lus à chaque rendu de la page (12 Ko) : en cache 1 h depuis le 28/10/2026, vidé par toute écriture de
     * LandingPageSetting (éditeur, modèles de site, assistant IA, retour en arrière).
     */
    #[Route('', name: 'api_landingpage_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $json = $this->cache->get('landingpage_settings', function (): string {
            $setting = $this->getUseCase->execute();
            if (empty($setting->getConfiguration())) {
                return 'null';
            }

            // JSON stocké restitué sans transformation (un {} reste {}, l'ordre des clés est conservé)
            return $this->service->getRawConfiguration($setting) ?? (string) json_encode($setting->getConfiguration());
        }, 3600, CacheTags::listTags(CacheTags::LANDING_SETTINGS));

        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    #[Route('', name: 'api_landingpage_settings_update', methods: ['PUT'])]
    public function updateSettings(#[MapRequestPayload] LandingPageSettingsInputDto $dto, Request $request): JsonResponse
    {
        // Corps relu en objets : un {} décodé en tableau PHP deviendrait [] (validation faussée, contenu modifié)
        $configuration = json_decode($request->getContent(), false, 512, JSON_BIGINT_AS_STRING)->configuration ?? null;

        $errors = $this->reglableValidator->validateConfiguration($configuration);
        if ($errors) {
            return $this->json([
                'error' => 'Composition réglable invalide',
                'message' => sprintf('%s : %s', $errors[0]['path'], $errors[0]['message']) . (count($errors) > 1 ? sprintf(' (et %d autre(s) erreur(s))', count($errors) - 1) : ''),
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (is_object($configuration)) {
            $dto->configuration = get_object_vars($configuration); // sous-objets conservés tels quels
        }
        $this->updateUseCase->execute($dto);

        return $this->json(['status' => 'Configuration sauvegardée']);
    }
}
