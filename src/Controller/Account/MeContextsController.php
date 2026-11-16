<?php

namespace App\Controller\Account;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Entry\EntryException;
use App\Services\Entry\EntryService;
use App\Services\Screens\ContextService;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Les contextes du compte connecté (lot AG-3a, `docs/ecrans-et-contextes.md`) : son mode, les sites qu'il peut ouvrir, et pour chacun
 * ses pastilles, son état, ses modules actifs et les écrans qu'un menu peut montrer. Lecture seule ; jamais en cache partagé.
 */
class MeContextsController extends AbstractController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly EntryService $entries,
        private readonly EntryContext $entry,
        private readonly TenantConnectionProvider $tenants,
        #[Autowire(service: 'limiter.entry_enter_account')] private readonly RateLimiterFactory $enterLimiter,
    ) {
    }

    #[Route('/api/me/contexts', name: 'api_me_contexts', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $response = new JsonResponse($this->contexts->contexts($this->getUser()?->getRoles() ?? []));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Lot AG-3b : ENTRER dans le site d'un client (agence) ou dans un site (compte de la plateforme), depuis son site d'attache.
     * Corps : `{ "site": "<code du site>" }`. Rend un jeton d'entrée de 15 minutes, signé pour ce site seulement ; on le renouvelle
     * en redemandant l'entrée d'ici. Un jeton d'entrée n'en obtient jamais un autre (403 `ENTRY_FROM_ENTRY`).
     */
    #[Route('/api/me/contexts/enter', name: 'api_me_contexts_enter', methods: ['POST'])]
    public function enter(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $user = $this->getUser();
        if (!$user instanceof User || $this->entry->active()) {
            return $this->refused('ENTRY_FROM_ENTRY', 403);
        }
        $home = (string) $this->tenants->getTenantCode();
        if (!$this->enterLimiter->create($home . '|' . $user->getId())->consume(1)->isAccepted()) {
            return $this->refused('RATE_LIMITED', 429);
        }
        $body = json_decode((string) $request->getContent(), true);
        $site = is_array($body) && is_string($body['site'] ?? null) ? $body['site'] : '';
        try {
            $response = new JsonResponse($this->entries->enter($user, $home, $site));
        } catch (EntryException $e) {
            return $this->refused($e->errorCode, $e->status, $e->reason);
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function refused(string $code, int $status, ?string $reason = null): JsonResponse
    {
        $response = new JsonResponse(['error' => $code, 'code' => $code] + ($reason !== null ? ['reason' => $reason] : []), $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
