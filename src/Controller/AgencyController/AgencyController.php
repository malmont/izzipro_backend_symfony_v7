<?php

namespace App\Controller\AgencyController;

use App\Security\AgencyRoles;
use App\Services\Agency\AgencyContext;
use App\Services\Agency\AgencyException;
use App\Services\Agency\AgencyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace de l'agence, en lecture (lot AG-1, 15/11/2026, `docs/agences-et-import.md` § 3). Rôles `ROLE_AGENCY` ou `ROLE_AGENCY_MEMBER`
 * (`access_control` `^/api/agency`), sur le site d'attache de l'agence seulement : l'agence est lue du site de la requête
 * (`AgencyContext`), jamais d'un paramètre. Ailleurs : 403 `AGENCY_SCOPE`.
 */
#[Route('/api/agency')]
class AgencyController extends AbstractController
{
    public function __construct(private readonly AgencyContext $context, private readonly AgencyService $agencies)
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

            return $this->json(['sites' => $sites, 'maxSites' => $agency['max_sites'], 'used' => count($sites)]);
        } catch (AgencyException $e) {
            return $this->json($e->body(), $e->status);
        }
    }
}
