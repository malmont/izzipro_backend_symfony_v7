<?php

namespace App\Controller\PlatformController;

use App\Services\Agency\AgencyException;
use App\Services\Agency\AgencyMemberService;
use App\Services\Agency\AgencyService;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agences, côté plateforme (lot AG-1, 15/11/2026, `docs/agences-et-import.md` § 3) : `ROLE_SUPER_ADMIN` seulement (`access_control`
 * `^/api/platform` et `#[IsGranted]`). Créer une agence avec son site d'attache, rattacher ou détacher un site, suspendre ou reprendre,
 * donner ou retirer un rôle d'agence à un compte du site d'attache, lire le journal. Aucune base de site gérée n'est touchée.
 * Erreurs : 422 `VALIDATION_FAILED { errors: [{ path, message }] }`, ou un code d'agence (`AgencyException`).
 */
#[Route('/api/platform/agencies')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformAgenciesController extends AbstractController
{
    public function __construct(private readonly AgencyService $agencies, private readonly AgencyMemberService $members, private readonly \App\Services\Agency\AgencySiteRequestService $requests)
    {
    }

    /** Les agences → 200 `{ agencies }` */
    #[Route('', name: 'api_platform_agencies_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['agencies' => $this->agencies->all()]);
    }

    /** Crée une agence en essai `{ code, name, homeTenantCode, contactEmail?, test? }` → 201 `{ agency }` */
    #[Route('', name: 'api_platform_agencies_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['agency' => $this->agencies->create($this->body($request), $this->by())], 201));
    }

    /** Une agence → 200 `{ agency, sites, members, events, requests }` */
    #[Route('/{id}', name: 'api_platform_agencies_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->agencies->show($id) + ['members' => $this->members->members($this->agencies->agency($id)),
            'requests' => $this->requests->requestsOf($this->agencies->agency($id))])); // lot AG-2 : les demandes de création de l'agence
    }

    /**
     * Limites d'une agence (lot AG-2) `{ maxSites?: int | null, dailySiteRequests?: int }` → 200 `{ agency }`. `maxSites` null :
     * illimité ; `dailySiteRequests` : garde-fou technique des demandes de création par 24 heures.
     */
    #[Route('/{id}', name: 'api_platform_agencies_limits', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function limits(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['agency' => $this->agencies->setLimits($id, $this->body($request), $this->by())]));
    }

    /** Rattache un site existant `{ tenantCode }` → 201 `{ agency, sites }` */
    #[Route('/{id}/sites', name: 'api_platform_agencies_attach', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attach(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->agencies->attach($id, $this->body($request)['tenantCode'] ?? null, $this->by()), 201));
    }

    /** Détache un site → 200 `{ agency, sites }` */
    #[Route('/{id}/sites/{tenantCode}', name: 'api_platform_agencies_detach', methods: ['DELETE'], requirements: ['id' => '\d+', 'tenantCode' => '[A-Za-z0-9_-]{1,50}'])]
    public function detach(int $id, string $tenantCode): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->agencies->detach($id, $tenantCode, $this->by())));
    }

    /** Suspend l'agence `{ reason? }` → 200 `{ agency }` */
    #[Route('/{id}/suspend', name: 'api_platform_agencies_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['agency' => $this->agencies->suspend($id, $this->by(), $this->body($request)['reason'] ?? null)]));
    }

    /** Lève la suspension → 200 `{ agency }` */
    #[Route('/{id}/resume', name: 'api_platform_agencies_resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resume(int $id): JsonResponse
    {
        return $this->handle(fn () => $this->json(['agency' => $this->agencies->resume($id, $this->by())]));
    }

    /** Donne ou retire le rôle d'agence d'un compte du site d'attache `{ email, role: manager | member | none }` → 200 `{ member, members }` */
    #[Route('/{id}/members', name: 'api_platform_agencies_member', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function member(int $id, Request $request): JsonResponse
    {
        return $this->handle(function () use ($id, $request) {
            $agency = $this->agencies->agency($id);
            $body = $this->body($request);
            $member = $this->members->set($agency, $body['email'] ?? null, $body['role'] ?? null, $this->by());

            return $this->json(['member' => $member, 'members' => $this->members->members($agency)]);
        });
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PlatformValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
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
