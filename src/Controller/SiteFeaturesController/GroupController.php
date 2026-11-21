<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Group\GroupException;
use App\Services\Group\GroupService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * LE MULTI-BOUTIQUES, côté SIÈGE et côté boutique (lot G-1a, 18/11/2026, `docs/ecrans-et-contextes.md` § 12). Administrateur DU site : un
 * opérateur d'agence, une entrée par jeton et (pour agir) le compte de la plateforme sont refusés, 403 `GROUP_FORBIDDEN`.
 * - GET `/api/group` → `{ group: null | { id, code, name, head, role: head | shop, maxShops }, shops: [{ code, name, origin, joinedAt }], canCreate }`
 * - POST `/api/group` « Passer en multi-boutiques » → 201 ; 403 `MODULE_INACTIVE` ; 409 `GROUP_ALREADY_MEMBER`
 * - POST `/api/group/invitations` `{ site }` → 202 `{ asked: true, expiresInDays }` TOUJOURS (que le site existe ou non) ; 403 `GROUP_HEAD_ONLY` ;
 *   429 `GROUP_INVITE_LIMIT` (10 demandes par jour et par groupe)
 * - lot G-INVITES : GET `/api/group/invitations` → `{ invitations: [{ id, code, sentAt, expiresAt, state: pending | expired }] }` (siège, module
 *   actif) ; DELETE `/api/group/invitations/{id}` → 204 (que le code existe ou non), 404 `GROUP_INVITATION_NOT_FOUND`
 * - POST `/api/group/leave` `{ shop? }` → 200 ; la boutique quitte, ou le siège retire une boutique ; le siège seul dissout ; 409 `GROUP_NOT_EMPTY`
 * - lot G-1d : POST `/api/group/shops/{code}/same-company` → 202 `{ asked, expiresInDays }` (le directeur DEMANDE le lien « même entreprise » ;
 *   409 `SAME_COMPANY_PENDING` | `SAME_COMPANY_ALREADY`, 422 `SAME_COMPANY_NOT_ASSOCIATED`, 404 `GROUP_SHOP_UNKNOWN`) ; DELETE → 200 (il retire
 *   le lien, ou sa demande en attente ; 409 `SAME_COMPANY_NOT_SET`)
 */
#[Route('/api/group')]
#[IsGranted('ROLE_ADMIN')]
class GroupController extends AbstractController
{
    public function __construct(private readonly GroupService $groups, private readonly EntryContext $entry, private readonly TenantConnectionProvider $tenants,
        private readonly \App\Services\Group\GroupCompanyService $company)
    {
    }

    #[Route('', name: 'api_group_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->merchant(false) === null ? $this->forbidden() : $this->json($this->groups->mine($this->site()));
    }

    #[Route('', name: 'api_group_create', methods: ['POST'])]
    public function create(): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->groups->create($this->site(), $user), 201));
    }

    #[Route('/invitations', name: 'api_group_invite', methods: ['POST'])]
    public function invite(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);

        return $this->act(fn (User $user) => $this->json($this->groups->invite($this->site(), is_array($body) ? ($body['site'] ?? null) : null, $user, (string) $request->query->get('locale', 'fr')), 202));
    }

    /** Lot G-INVITES : la liste des invitations demandées par le siège (base maître seulement) ; refusée à toute personne entrée et à la plateforme */
    #[Route('/invitations', name: 'api_group_invitations', methods: ['GET'])]
    public function invitations(): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->groups->invitationRequests($this->site())));
    }

    /** Lot G-INVITES : le siège annule une demande ; 204 que le code existe ou non, 404 pour une demande inconnue ou d'un autre groupe */
    #[Route('/invitations/{id}', name: 'api_group_invitation_cancel', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancelInvitation(int $id): JsonResponse
    {
        return $this->act(function (User $user) use ($id): JsonResponse {
            $this->groups->cancelInvitationRequest($this->site(), $id, $user);

            return new JsonResponse(null, 204);
        });
    }

    #[Route('/leave', name: 'api_group_leave', methods: ['POST'])]
    public function leave(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $shop = is_array($body) && is_string($body['shop'] ?? null) ? trim($body['shop']) : null;

        return $this->act(fn (User $user) => $this->json($this->groups->leave($this->site(), $shop, $user)));
    }

    #[Route('/shops/{code}/same-company', name: 'api_group_same_company_request', methods: ['POST'], requirements: ['code' => '[a-z0-9][a-z0-9_-]{0,49}'])]
    public function requestSameCompany(string $code, Request $request): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->company->request($this->site(), $code, $user, (string) $request->query->get('locale', 'fr') === 'en' ? 'en' : 'fr'), 202));
    }

    #[Route('/shops/{code}/same-company', name: 'api_group_same_company_remove', methods: ['DELETE'], requirements: ['code' => '[a-z0-9][a-z0-9_-]{0,49}'])]
    public function removeSameCompany(string $code, Request $request): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->company->removeByHead($this->site(), $code, $user, (string) $request->query->get('locale', 'fr') === 'en' ? 'en' : 'fr')));
    }

    /** @param callable(User): JsonResponse $action */
    private function act(callable $action): JsonResponse
    {
        $user = $this->merchant(true);
        if ($user === null) {
            return $this->forbidden();
        }
        try {
            return $action($user);
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    private function merchant(bool $write): ?User
    {
        $user = $this->getUser();

        return $user instanceof User && !$this->entry->active() && !($write && PlatformAccessJournal::isPlatformAccount($user->getRoles())) ? $user : null;
    }

    private function site(): string
    {
        return (string) $this->tenants->getTenantCode();
    }

    private function forbidden(): JsonResponse
    {
        return $this->json(['error' => 'GROUP_FORBIDDEN', 'code' => 'GROUP_FORBIDDEN', 'message' => 'Seul un administrateur du site gère son groupe de boutiques.'], 403);
    }
}
