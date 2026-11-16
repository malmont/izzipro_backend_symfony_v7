<?php

namespace App\Controller\AppProfileController;

use App\Entity\AppProfile;
use App\Services\MediaUrlResolver;
use App\UseCase\AppProfileUseCase\GetAppProfileUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profil public d'une application du site (20/10/2026), sans connexion (access_control `^/api/app-profile/`, GET).
 * Le site est celui de X-Tenant-Host, comme partout. Contrat : docs/architecture.md, « Profils des applications ».
 */
class AppProfileController extends AbstractController
{
    public function __construct(private readonly GetAppProfileUseCase $profile, private readonly ?MediaUrlResolver $mediaUrlResolver = null,
        private readonly ?\App\Services\Storefront\StorefrontService $storefront = null, private readonly ?\App\Security\StorefrontViewer $viewer = null)
    {
    }

    #[Route('/api/app-profile/{app}', name: 'api_app_profile', methods: ['GET'], requirements: ['app' => '[a-zA-Z0-9_-]{1,30}'])]
    public function show(string $app, Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
        $result = $this->profile->execute($app, $host, (string) $request->query->get('locale', 'fr'));
        if ($result === null) {
            return $this->json(['error' => 'APP_UNKNOWN', 'message' => sprintf('Application inconnue : « %s »', $app), 'apps' => array_keys(AppProfile::APPS)], 404);
        }

        // Lot CONSTRUCTION (16/11/2026) : sur un site NEUF en construction, le visiteur ne lit pas par ce profil ce que la fiche réduite
        // lui cache (présentation, accroche, adresse du site) ; les profils de Mémoires Vivantes, Living Memory et Boussole, eux, ne
        // dépendent pas de la boutique
        if (is_array($result) && !in_array($app, ['memoires', 'livingmemory', 'boussole'], true) && $this->storefront?->closure() === \App\Services\Storefront\StorefrontService::SCOPE_SITE
            && !($this->viewer?->isTeam($request) ?? false) && !$this->storefront->previewValid($request->headers->get(\App\Services\Storefront\StorefrontService::PREVIEW_HEADER))) {
            foreach (['apropos', 'tagline', 'website'] as $field) {
                if (array_key_exists($field, $result)) {
                    $result[$field] = null;
                }
            }
        }

        return $this->json($result);
    }
}
