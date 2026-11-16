<?php

namespace App\Controller\AgencyController;

use App\Security\AgencyRoles;
use App\Services\Agency\AgencyContext;
use App\Services\Agency\AgencyException;
use App\Services\Agency\AgencyService;
use App\Services\Agency\AgencySiteRequestService;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace de l'agence, en lecture (lot AG-1, 15/11/2026, `docs/agences-et-import.md` § 3). Rôles `ROLE_AGENCY` ou `ROLE_AGENCY_MEMBER`
 * (`access_control` `^/api/agency`), sur le site d'attache de l'agence seulement : l'agence est lue du site de la requête
 * (`AgencyContext`), jamais d'un paramètre. Ailleurs : 403 `AGENCY_SCOPE`.
 */
#[Route('/api/agency')]
class AgencyController extends AbstractController
{
    public function __construct(private readonly AgencyContext $context, private readonly AgencyService $agencies, private readonly AgencySiteRequestService $requests,
        private readonly \App\Services\Storefront\StorefrontService $storefront)
    {
    }

    /** Mon agence → 200 `{ agency, role }` : son état, la fin de son essai, combien de sites sur combien ; `role` : manager | member */
    #[Route('/me', name: 'api_agency_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        try {
            return $this->json(['agency' => $this->agencies->view($this->context->current()), 'role' => AgencyRoles::nameOf($this->getUser()?->getRoles() ?? [])]);
        } catch (AgencyException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    /** Les sites que mon agence gère → 200 `{ sites: [{ code, name, host, origin, attachedAt }], maxSites, used }` */
    #[Route('/sites', name: 'api_agency_sites', methods: ['GET'])]
    public function sites(): JsonResponse
    {
        try {
            $agency = $this->context->current();
            $sites = $this->agencies->sitesOf($agency);

            // maxSites null : illimité ; `used` : les sites gérés ; `pending` : les demandes de création en cours, qui retiennent chacune une place (lot AG-2)
            $view = $this->agencies->view($agency);

            return $this->json(['sites' => $sites, 'maxSites' => $agency['max_sites'], 'used' => count($sites), 'pending' => $view['sitesUsed'] - count($sites)]);
        } catch (AgencyException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    // --- Lot AG-2 (15/11/2026) : création d'un site par l'agence. Écrire est réservé au responsable (`ROLE_AGENCY`) ; le collaborateur lit.

    /** Ce nom de site est-il libre ? `?code=` → 200 `{ code, available, reason: INVALID | RESERVED | TAKEN | null }` */
    #[Route('/sites/availability', name: 'api_agency_sites_availability', methods: ['GET'], priority: 5)]
    public function availability(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $this->context->current();

            return $this->json($this->requests->availability($request->query->get('code')));
        });
    }

    /**
     * Demande la création d'un site `{ code, name, adminEmail, adminFirstName?, adminLastName?, locale?, country?, province?, currency?,
     * timezone?, modules? }` → 202 `{ request }` (« pending ») ; le site est fabriqué en tâche de fond.
     */
    #[Route('/sites', name: 'api_agency_sites_request', methods: ['POST'])]
    public function requestSite(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->requests->request($this->manager(), $this->body($request), $this->by())], 202));
    }

    /** Les demandes de création de mon agence → 200 `{ requests }`, de la plus récente à la plus ancienne */
    #[Route('/site-requests', name: 'api_agency_site_requests', methods: ['GET'])]
    public function siteRequests(): JsonResponse
    {
        return $this->handle(fn () => $this->json(['requests' => $this->requests->requestsOf($this->context->current())]));
    }

    /** Une demande → 200 `{ request }` : pending | building | ready | failed, avec `errorCode`, puis `site`, `invitation`, `modules` */
    #[Route('/site-requests/{id}', name: 'api_agency_site_request', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function siteRequest(int $id): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->requests->show($this->context->current(), $id)]));
    }

    /** Les modules d'un site que mon agence a créé → 200 `{ modules: [{ key, label, enabled, byAgency, status, graceUntil }] }` */
    #[Route('/sites/{code}/modules', name: 'api_agency_site_modules', methods: ['GET'], requirements: ['code' => '[a-z0-9-]{1,50}'])]
    public function modules(string $code): JsonResponse
    {
        return $this->handle(fn () => $this->json(['modules' => $this->requests->modules($this->context->current(), $code)]));
    }

    /** Ouvre ou ferme un module d'un site que mon agence a créé `{ enabled }` → 200 `{ modules }` */
    #[Route('/sites/{code}/modules/{feature}', name: 'api_agency_site_module_set', methods: ['PUT'], requirements: ['code' => '[a-z0-9-]{1,50}', 'feature' => '[a-z_.]{1,40}'])]
    public function setModule(string $code, string $feature, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['modules' => $this->requests->setModule($this->manager(), $code, $feature, $this->body($request)['enabled'] ?? null, $this->by())]));
    }

    /**
     * Renvoie à l'administrateur désigné d'un site créé par mon agence le lien pour choisir son mot de passe → 202 `{ request }`.
     * `{ adminEmail }` (facultatif) désigne une autre personne, tant que la première n'a pas activé son compte (409
     * `AGENCY_ADMIN_ACTIVE` ensuite). Un renvoi par site toutes les 10 minutes (429 `AGENCY_INVITATION_TOO_SOON`).
     */
    #[Route('/sites/{code}/invitation', name: 'api_agency_site_invitation', methods: ['POST'], requirements: ['code' => '[a-z0-9-]{1,50}'])]
    public function invitation(string $code, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['request' => $this->requests->askInvitation($this->manager(), $code, $this->by(), $this->body($request)['adminEmail'] ?? null)], 202));
    }

    // --- Lot CONSTRUCTION (16/11/2026) : un site créé par l'agence naît « en construction » ; elle lit son état, l'ouvre, et obtient un lien d'aperçu

    /** État et liste de contrôle d'un site que mon agence a créé → 200 `{ state, openedAt, canOpen, items }` */
    #[Route('/sites/{code}/storefront', name: 'api_agency_site_storefront', methods: ['GET'], requirements: ['code' => '[a-z0-9-]{1,50}'])]
    public function storefront(string $code): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->checklist($this->requests->createdSiteOf($this->context->current(), $code))));
    }

    /** Ouvre la boutique d'un site que mon agence a créé (responsable) → 200 `{ state, openedAt }`, ou 409 `STOREFRONT_NOT_READY { items }` */
    #[Route('/sites/{code}/storefront/open', name: 'api_agency_site_storefront_open', methods: ['POST'], requirements: ['code' => '[a-z0-9-]{1,50}'])]
    public function storefrontOpen(string $code): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->open($this->requests->createdSiteOf($this->manager(), $code, true), $this->by())));
    }

    /** Un lien d'aperçu neuf d'un site que mon agence a créé (responsable) → 200 `{ url, expiresAt }` (7 jours ; il remplace l'ancien) */
    #[Route('/sites/{code}/storefront/preview-link', name: 'api_agency_site_storefront_preview_link', methods: ['POST'], requirements: ['code' => '[a-z0-9-]{1,50}'])]
    public function storefrontPreviewLink(string $code): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->storefront->previewLink($this->requests->createdSiteOf($this->manager(), $code, true), $this->by()), 200, ['Cache-Control' => 'no-store']));
    }

    /** @return array<string, mixed> mon agence, pour un geste réservé à son responsable ; 403 `AGENCY_MANAGER_ONLY` pour un collaborateur */
    private function manager(): array
    {
        $agency = $this->context->current();
        if (!in_array(AgencyRoles::MANAGER, $this->getUser()?->getRoles() ?? [], true)) {
            throw new AgencyException('AGENCY_MANAGER_ONLY', 403);
        }

        return $agency;
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PlatformValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (AgencyException $e) {
            return $this->json($e->body(), $e->status);
        } catch (\App\Services\Storefront\StorefrontException $e) {
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
