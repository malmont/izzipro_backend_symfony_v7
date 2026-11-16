<?php

namespace App\Controller\PlatformController;

use App\Services\Agency\AgencyException;
use App\Services\Platform\Sites\PlatformSiteException;
use App\Services\Platform\Sites\PlatformSiteService;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Création d'un site avec son type par le super administrateur (lot TYPE-1, 16/11/2026, `docs/agences-et-import.md` § 9). Une seule
 * route de création pour l'écran « Plateforme : sites » d'EasyAdmin et pour le panneau du front : le type (normal, agence) est un
 * raccourci qui enchaîne les gestes existants, jamais un champ enregistré. `ROLE_SUPER_ADMIN` (règle `^/api/platform` et attribut).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformSitesController extends AbstractController
{
    public function __construct(private readonly PlatformSiteService $sites)
    {
    }

    /**
     * Les sites de la plateforme avec leurs pastilles calculées (`?badge=independent | agency_home | agency_managed |
     * creation_incomplete`) → 200 `{ sites, badges }`. (`GET /api/platform/sites` est déjà la grille sites × fonctionnalités de la console.)
     */
    #[Route('/api/platform/sites/badges', name: 'api_platform_sites_badges', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $badge = $request->query->get('badge');

        return $this->handle(fn () => $this->json(['sites' => $this->sites->sites(is_string($badge) && $badge !== '' ? $badge : null), 'badges' => PlatformSiteService::BADGES]));
    }

    /**
     * Demande la création d'un site `{ type: normal | agency, code, name, adminEmail, adminFirstName?, adminLastName?, locale?, modules?,
     * agency?: { name, code?, contactEmail?, isTest?, trialDays?, maxSites?, managerRole? } }` → 202 `{ request }` : la demande est
     * écrite, ses étapes sont déroulées en tâche de fond.
     */
    #[Route('/api/platform/sites', name: 'api_platform_sites_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->sites->request($this->body($request), $this->by())], 202));
    }

    /** Les demandes de création, de la plus récente à la plus ancienne → 200 `{ requests }` */
    #[Route('/api/platform/site-requests', name: 'api_platform_site_requests_list', methods: ['GET'])]
    public function requests(): JsonResponse
    {
        return $this->json(['requests' => $this->sites->all()]);
    }

    /** Une demande : son état, ses étapes, l'étape en échec et son code → 200 `{ request }` */
    #[Route('/api/platform/site-requests/{id}', name: 'api_platform_site_requests_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->sites->show($id)]));
    }

    /**
     * « Reprendre » une demande à compléter : ses seules étapes manquantes sont rejouées → 202 `{ request }` ; 409
     * `PLATFORM_SITE_REQUEST_STATE` sinon. Corps facultatif `{ adminEmail }` : désigner une AUTRE adresse pour l'administrateur, tant
     * que son invitation n'est pas partie (la première était fausse, ou déjà celle d'un compte).
     */
    #[Route('/api/platform/site-requests/{id}/resume', name: 'api_platform_site_requests_resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resume(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->sites->resume($id, $this->by(), $this->body($request)['adminEmail'] ?? null)], 202));
    }

    /** « Abandonner » une demande à compléter ou pas encore commencée : le site, s'il existe, reste un site normal → 200 `{ request }` */
    #[Route('/api/platform/site-requests/{id}/abandon', name: 'api_platform_site_requests_abandon', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function abandon(int $id): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->sites->abandon($id, $this->by())]));
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PlatformValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (PlatformSiteException $e) {
            return $this->json($e->body(), $e->status);
        } catch (AgencyException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function by(): ?string
    {
        return $this->getUser()?->getUserIdentifier();
    }
}
