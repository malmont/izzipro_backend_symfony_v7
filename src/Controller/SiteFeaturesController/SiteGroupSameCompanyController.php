<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\User;
use App\Security\Entry\EntryContext;
use App\Services\Group\GroupCompanyService;
use App\Services\Group\GroupException;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lot G-1d : le lien « même entreprise », côté BOUTIQUE ASSOCIÉE. Administrateur DU site seulement : ni entrée par jeton (le directeur entré ne
 * confirme JAMAIS à la place de la boutique), ni agence, ni compte de la plateforme (403 `GROUP_FORBIDDEN`). L'assistant IA n'a aucun outil ici.
 * - GET → `{ sameCompany, since, request: null | { id, group, head, expiresAt, consent, consentVersion } }`
 * - POST `/{id}/confirm` `{ consent: <consentVersion> }` → 200 ; 422 `GROUP_CONSENT_REQUIRED` ; 404 `SAME_COMPANY_REQUEST_NOT_FOUND`
 * - POST `/{id}/refuse` → 204
 * - DELETE → 200 : la boutique retire le lien SEULE, effet immédiat (le directeur est prévenu) ; 409 `SAME_COMPANY_NOT_SET`
 */
#[Route('/api/site/group-same-company')]
#[IsGranted('ROLE_ADMIN')]
class SiteGroupSameCompanyController extends AbstractController
{
    public function __construct(private readonly GroupCompanyService $company, private readonly EntryContext $entry, private readonly TenantConnectionProvider $tenants)
    {
    }

    #[Route('', name: 'api_site_group_same_company', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        return $this->merchant() === null ? $this->forbidden() : $this->json($this->company->forShop($this->site(), self::language($request)));
    }

    #[Route('/{id}/confirm', name: 'api_site_group_same_company_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);

        return $this->act(fn (User $user) => $this->json($this->company->confirm($this->site(), $id, is_array($body) ? ($body['consent'] ?? null) : null, $user, self::language($request))));
    }

    #[Route('/{id}/refuse', name: 'api_site_group_same_company_refuse', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refuse(int $id, Request $request): JsonResponse
    {
        return $this->act(function (User $user) use ($id, $request): JsonResponse {
            $this->company->refuse($this->site(), $id, $user, self::language($request));

            return new JsonResponse(null, 204);
        });
    }

    #[Route('', name: 'api_site_group_same_company_remove', methods: ['DELETE'])]
    public function remove(Request $request): JsonResponse
    {
        return $this->act(fn (User $user) => $this->json($this->company->removeByShop($this->site(), $user, self::language($request))));
    }

    /** @param callable(User): JsonResponse $action */
    private function act(callable $action): JsonResponse
    {
        $user = $this->merchant();
        if ($user === null) {
            return $this->forbidden();
        }
        try {
            return $action($user);
        } catch (GroupException $e) {
            return $this->json($e->body(), $e->status);
        }
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

    private static function language(Request $request): string
    {
        return (string) $request->query->get('locale', 'fr') === 'en' ? 'en' : 'fr';
    }

    private function forbidden(): JsonResponse
    {
        return $this->json(['error' => 'GROUP_FORBIDDEN', 'code' => 'GROUP_FORBIDDEN', 'message' => 'Seul un administrateur de la boutique répond au lien « même entreprise ».'], 403);
    }
}
