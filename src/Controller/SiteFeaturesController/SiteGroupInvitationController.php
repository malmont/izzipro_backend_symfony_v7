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
 * Les invitations à rejoindre un groupe, côté BOUTIQUE INVITÉE (lot G-1a). Administrateur DU site seulement (ni agence entrée, ni entrée par
 * jeton, ni compte de la plateforme : 403 `GROUP_FORBIDDEN`). Jamais d'ajout silencieux : accepter exige le texte de consentement.
 * - GET → `{ invitations: [{ id, group, head, expiresAt, consent, consentVersion }] }`
 * - POST `/{id}/accept` `{ consent: <consentVersion>, sameCompany?: true, sameCompanyConsent?: <sameCompanyConsentVersion> }` → 200 (le groupe) ;
 *   422 `GROUP_CONSENT_REQUIRED` (l'un ou l'autre texte) ; 404 `GROUP_INVITATION_NOT_FOUND` ;
 *   409 `GROUP_ALREADY_MEMBER` | `GROUP_SHOP_LIMIT`
 * - POST `/{id}/refuse` → 204
 */
#[Route('/api/site/group-invitations')]
#[IsGranted('ROLE_ADMIN')]
class SiteGroupInvitationController extends AbstractController
{
    public function __construct(private readonly GroupService $groups, private readonly EntryContext $entry, private readonly TenantConnectionProvider $tenants)
    {
    }

    #[Route('', name: 'api_site_group_invitations', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->merchant() === null ? $this->forbidden() : $this->json(['invitations' => $this->groups->invitationsFor($this->site(), (string) $request->query->get('locale', 'fr') === 'en' ? 'en' : 'fr')]);
    }

    #[Route('/{id}/accept', name: 'api_site_group_invitation_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(int $id, Request $request): JsonResponse
    {
        $user = $this->merchant();
        if ($user === null) {
            return $this->forbidden();
        }
        $body = json_decode((string) $request->getContent(), true);
        try {
            return $this->json($this->groups->accept($this->site(), $id, is_array($body) ? ($body['consent'] ?? null) : null, $user,
                is_array($body) ? ($body['sameCompany'] ?? null) : null, is_array($body) ? ($body['sameCompanyConsent'] ?? null) : null, (string) $request->query->get('locale', 'fr') === 'en' ? 'en' : 'fr'));
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    #[Route('/{id}/refuse', name: 'api_site_group_invitation_refuse', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refuse(int $id): JsonResponse
    {
        $user = $this->merchant();
        if ($user === null) {
            return $this->forbidden();
        }
        try {
            $this->groups->refuse($this->site(), $id, $user);
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }

        return new JsonResponse(null, 204);
    }

    private function merchant(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User && !$this->entry->active() && !PlatformAccessJournal::isPlatformAccount($user->getRoles()) ? $user : null;
    }

    private function site(): string
    {
        return (string) $this->tenants->getTenantCode();
    }

    private function forbidden(): JsonResponse
    {
        return $this->json(['error' => 'GROUP_FORBIDDEN', 'code' => 'GROUP_FORBIDDEN', 'message' => 'Seul un administrateur du site répond à une invitation de groupe.'], 403);
    }
}
