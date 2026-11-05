<?php

namespace App\Controller\SiteFeaturesController;

use App\Services\Platform\CustomDomainService;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Domaine propre du site (console de pilotage, 05/11/2026), administrateur du site : GET → { domain, platformHost, state: none | dns_pending |
 * dns_ok | live, expected: { ips, cnameTarget }, checks: { dns: { ok, found, cname }, https: { ok, status, checked } }, instructions[] } ;
 * PUT { domain } enregistre (422 DOMAIN_INVALID, 409 DOMAIN_TAKEN) ; DELETE retire ; POST /check relance les vérifications. Fiche :
 * docs/console-pilotage.md.
 */
#[Route('/api/site/domain')]
#[IsGranted('ROLE_ADMIN')]
class SiteDomainController extends AbstractController
{
    public function __construct(private readonly CustomDomainService $domains, private readonly TenantConnectionManager $tenants)
    {
    }

    #[Route('', name: 'api_site_domain_get', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->domains->status($this->code()));
    }

    #[Route('/check', name: 'api_site_domain_check', methods: ['POST'])]
    public function check(): JsonResponse
    {
        return $this->json($this->domains->status($this->code()));
    }

    #[Route('', name: 'api_site_domain_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $domain = is_array($data) && is_string($data['domain'] ?? null) ? $data['domain'] : '';
        try {
            return $this->json($this->domains->set($this->code(), $domain, $this->getUser()?->getUserIdentifier()));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'DOMAIN_INVALID', 'code' => 'DOMAIN_INVALID', 'message' => $e->getMessage(), 'errors' => [['path' => 'domain', 'message' => $e->getMessage()]]], 422);
        } catch (\DomainException $e) {
            return $this->json(['error' => 'DOMAIN_TAKEN', 'code' => 'DOMAIN_TAKEN', 'message' => $e->getMessage()], 409);
        }
    }

    #[Route('', name: 'api_site_domain_delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        return $this->json($this->domains->remove($this->code(), $this->getUser()?->getUserIdentifier()));
    }

    private function code(): string
    {
        return (string) $this->tenants->getCurrentTenantCode();
    }
}
