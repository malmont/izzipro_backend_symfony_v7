<?php

namespace App\Controller\SiteFeaturesController;

use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\EntrepriseService\EntrepriseForbiddenException;
use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\FeatureAccess\SiteFeatureStateService;
use App\Services\FeatureAccess\SiteFeaturesValidationException;
use App\Services\Platform\PlatformModeService;
use App\UseCase\SiteFeaturesUseCase\RequestSiteFeatureUseCase;
use App\UseCase\SiteFeaturesUseCase\SiteFeatureRequestException;
use App\UseCase\SiteFeaturesUseCase\UpdateSiteFeaturesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Registre des fonctionnalités du site (console de pilotage, lot 2, 04/11/2026), lu et écrit par le panneau « Commerce » de
 * l'éditeur et par les écrans EasyAdmin. GET → { features: [...] } ; PUT { values: { clé: valeur } } → même réponse après
 * écriture ; 422 VALIDATION_FAILED (errors[{path, message}], path = clé du registre), 403 SUPER_ADMIN_REQUIRED (fields).
 * Contrat : docs/console-pilotage.md.
 */
#[Route('/api/site/features')]
#[IsGranted('ROLE_ADMIN')]
class SiteFeaturesController extends AbstractController
{
    // correspondance chemin de la cible → clé du registre : FeatureCatalog::keyForStorage (activations : PLATFORM_TO_KEY)
    private const PLATFORM_TO_KEY = ['isLandingPageActive' => 'module.landing', 'isBoutiqueActive' => 'module.boutique', 'isPosActive' => 'module.pos', 'isMemoireVivanteActive' => 'module.memoires', 'isBoussoleEsgActive' => 'module.boussole'];

    public function __construct(private readonly SiteFeatureStateService $state, private readonly UpdateSiteFeaturesUseCase $update, private readonly PlatformModeService $platform, private readonly RequestSiteFeatureUseCase $requestFeature)
    {
    }

    #[Route('', name: 'api_site_features_get', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(['platformMode' => $this->platform->mode(), 'features' => $this->state->all(), 'groups' => ['localisation', 'modules', 'apps', 'commerce', 'services']]);
    }

    #[Route('', name: 'api_site_features_put', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $values = is_array($data) ? ($data['values'] ?? null) : null;
        if (!is_array($values) || $values === []) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'values : objet { clé: valeur } attendu, non vide', 'errors' => [['path' => 'values', 'message' => 'objet { clé: valeur } attendu, non vide']]], 422);
        }
        $host = $request->getSchemeAndHttpHost();
        try {
            $this->update->execute($values, $host, (string) $request->get('locale', 'fr'));
        } catch (SiteFeaturesValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (EntrepriseForbiddenException $e) {
            $fields = array_map(fn ($f) => self::PLATFORM_TO_KEY[$f] ?? FeatureCatalog::keyForStorage($f), $e->fields);

            return $this->json(['error' => $e::CODE, 'code' => $e::CODE, 'message' => 'Modification réservée au super administrateur : ' . implode(', ', $fields), 'fields' => $fields], 403);
        } catch (EntrepriseValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $this->mapErrors($e->errors)], 422);
        } catch (BoutiqueSettingsException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $this->mapErrors($e->errors ?? [])], $e->getStatusCode() === 422 ? 422 : 400);
        }

        return $this->show();
    }

    /**
     * « Demander » une fonctionnalité (console, lot 3, 04/11/2026 ; règle du 05/11/2026) : essai gratuit ouvert aussitôt si la politique le permet
     * (201 { kind: trial, entitlement }), sinon demande à la plateforme (201 { kind: request, request }) ; 409 ALREADY_ENABLED | REQUEST_PENDING ;
     * 422 clé inconnue ou non plateforme.
     */
    #[Route('/{key}/request', name: 'api_site_features_request', methods: ['POST'], requirements: ['key' => '[a-z_]+\.[a-z_]+'])]
    public function request(string $key, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = is_array($data) && isset($data['message']) && is_string($data['message']) ? $data['message'] : null;
        try {
            $outcome = $this->requestFeature->execute($key, $message, $this->getUser()?->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => $key, 'message' => $e->getMessage()]]], 422);
        } catch (SiteFeatureRequestException $e) {
            return $this->json(['error' => $e->code409, 'code' => $e->code409, 'message' => $e->getMessage()], 409);
        }

        return $this->json($outcome + ['request' => $outcome['request'] ?? null], 201);
    }

    /** @param list<array{path: string, message: string}> $errors */
    private function mapErrors(array $errors): array
    {
        return array_map(fn (array $err) => ['path' => self::PLATFORM_TO_KEY[$err['path']] ?? FeatureCatalog::keyForStorage($err['path']), 'message' => $err['message']], $errors);
    }
}
