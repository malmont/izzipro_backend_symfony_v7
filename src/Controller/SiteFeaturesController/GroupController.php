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
 * - POST `/api/group/leave` `{ shop? }` → 200 ; la boutique quitte, ou le siège retire une boutique ; le siège seul dissout ; 409 `GROUP_NOT_EMPTY`
 */
#[Route('/api/group')]
#[IsGranted('ROLE_ADMIN')]
class GroupController extends AbstractController
{
    public function __construct(private readonly GroupService $groups, private readonly EntryContext $entry, private readonly TenantConnectionProvider $tenants)
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

    #[Route('/leave', name: 'api_group_leave', methods: ['POST'])]
    public function leave(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $shop = is_array($body) && is_string($body['shop'] ?? null) ? trim($body['shop']) : null;

        return $this->act(fn (User $user) => $this->json($this->groups->leave($this->site(), $shop, $user)));
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
