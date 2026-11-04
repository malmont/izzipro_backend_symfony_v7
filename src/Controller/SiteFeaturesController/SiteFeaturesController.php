<?php

namespace App\Controller\SiteFeaturesController;

use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\EntrepriseService\EntrepriseForbiddenException;
use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\FeatureAccess\SiteFeatureStateService;
use App\Services\FeatureAccess\SiteFeaturesValidationException;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantConnectionManager;
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
    /** clé de la cible → clé du registre, pour rendre les erreurs des cas d'usage sous-jacents dans le vocabulaire du registre */
    private const STORAGE_TO_KEY = ['siteLocale' => 'site.locale', 'posTimezone' => 'site.timezone', 'currency' => 'site.currency', 'pricesIncludeTax' => 'site.prices_include_tax',
        'isLandingPageActive' => 'module.landing', 'isBoutiqueActive' => 'module.boutique', 'isPosActive' => 'module.pos', 'isMemoireVivanteActive' => 'module.memoires', 'isBoussoleEsgActive' => 'module.boussole',
        'commerce.salesModes' => 'commerce.sales_modes', 'commerce.guestCheckout' => 'commerce.guest_checkout', 'commerce.subscriptionsEnabled' => 'commerce.subscriptions', 'commerce.taxProvider' => 'commerce.tax_provider'];

    public function __construct(private readonly SiteFeatureStateService $state, private readonly UpdateSiteFeaturesUseCase $update, private readonly PlatformModeService $platform, private readonly EntitlementService $entitlements, private readonly FeatureRequestRepository $requests, private readonly TenantConnectionManager $tenants)
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
            $fields = array_map(fn ($f) => self::STORAGE_TO_KEY[$f] ?? $f, $e->fields);

            return $this->json(['error' => $e::CODE, 'code' => $e::CODE, 'message' => 'Modification réservée au super administrateur : ' . implode(', ', $fields), 'fields' => $fields], 403);
        } catch (EntrepriseValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $this->mapErrors($e->errors)], 422);
        } catch (BoutiqueSettingsException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $this->mapErrors($e->errors ?? [])], $e->getStatusCode() === 422 ? 422 : 400);
        }

        return $this->show();
    }

    /**
     * Demande d'une fonctionnalité de la plateforme (console, lot 3, 04/11/2026) : file de la console super administrateur (plus tard,
     * redirection vers la boutique de la plateforme). 201 { request } ; 409 ALREADY_ENABLED | REQUEST_PENDING ; 422 clé inconnue ou non plateforme.
     */
    #[Route('/{key}/request', name: 'api_site_features_request', methods: ['POST'], requirements: ['key' => '[a-z_]+\.[a-z_]+'])]
    public function request(string $key, Request $request): JsonResponse
    {
        if (!isset(FeatureCatalog::GOVERNED[$key])) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'clé inconnue ou non accordée par la plateforme', 'errors' => [['path' => $key, 'message' => 'clé inconnue ou non accordée par la plateforme']]], 422);
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        if ($this->entitlements->isEnabled($code, $key)) {
            return $this->json(['error' => 'ALREADY_ENABLED', 'code' => 'ALREADY_ENABLED', 'message' => 'Fonctionnalité déjà active sur ce site.'], 409);
        }
        if (isset($this->requests->pendingFor($code)[$key])) {
            return $this->json(['error' => 'REQUEST_PENDING', 'code' => 'REQUEST_PENDING', 'message' => 'Une demande est déjà en attente pour cette fonctionnalité.'], 409);
        }
        $data = json_decode($request->getContent(), true);
        $message = is_array($data) && isset($data['message']) && is_string($data['message']) ? trim($data['message']) : null;
        $row = $this->requests->insert($code, $key, $this->getUser()?->getUserIdentifier(), $message !== '' ? $message : null);

        return $this->json(['request' => $row], 201);
    }

    /** @param list<array{path: string, message: string}> $errors */
    private function mapErrors(array $errors): array
    {
        return array_map(fn (array $err) => ['path' => self::STORAGE_TO_KEY[$err['path']] ?? $err['path'], 'message' => $err['message']], $errors);
    }
}
