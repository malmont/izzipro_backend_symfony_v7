<?php

namespace App\Controller\PlatformController;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementRepository;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\Platform\PlatformConsoleService;
use App\UseCase\PlatformUseCase\ManagePlatformEntitlementsUseCase;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Console de la plateforme par l'API (lot 4, 04/11/2026), super administrateur. Codes : 422 VALIDATION_FAILED (errors[{path, message}]),
 * 404 NOT_FOUND, 409 POLICY_FORBIDDEN (politique ou état incompatible). Contrat : docs/console-pilotage.md.
 */
#[Route('/api/platform')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformEntitlementsController extends AbstractController
{
    public function __construct(
        private readonly PlatformConsoleService $console,
        private readonly ManagePlatformEntitlementsUseCase $manage,
        private readonly EntitlementService $entitlements,
        private readonly EntitlementRepository $repository,
        private readonly FeatureRequestRepository $requests,
    ) {
    }

    /** Grille sites × fonctionnalités gouvernées, demandes en attente, catalogue des fonctionnalités */
    #[Route('/sites', name: 'api_platform_sites', methods: ['GET'])]
    public function sites(): JsonResponse
    {
        return $this->json(['features' => array_map(fn (string $key) => ['key' => $key, 'label' => FeatureCatalog::label($key)], array_keys(FeatureCatalog::GOVERNED)), 'sites' => $this->console->grid()]);
    }

    #[Route('/entitlements', name: 'api_platform_entitlements_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $tenant = trim((string) $request->query->get('tenant', ''));
        if ($tenant === '' || !$this->console->tenantExists($tenant)) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'tenant : code de site existant attendu', 'errors' => [['path' => 'tenant', 'message' => 'code de site existant attendu']]], 422);
        }

        return $this->json(['tenant' => $tenant, 'effective' => $this->entitlements->effective($tenant), 'entitlements' => $this->entitlements->listForTenant($tenant), 'pendingRequests' => array_keys($this->requests->pendingFor($tenant))]);
    }

    #[Route('/entitlements', name: 'api_platform_entitlements_grant', methods: ['POST'])]
    public function grant(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['entitlement' => $this->manage->grant($this->body($request), $this->getUser()?->getUserIdentifier())], 201));
    }

    #[Route('/entitlements/{id}', name: 'api_platform_entitlements_act', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function act(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['entitlement' => $this->manage->act($id, $this->body($request), $this->getUser()?->getUserIdentifier())]));
    }

    #[Route('/policies', name: 'api_platform_policies', methods: ['GET'])]
    public function policies(): JsonResponse
    {
        return $this->json(['policies' => array_values($this->console->policies())]);
    }

    #[Route('/policies/{feature}', name: 'api_platform_policy_put', methods: ['PUT'], requirements: ['feature' => '[a-z_]+\.[a-z_]+'])]
    public function policy(string $feature, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json(['policy' => $this->manage->policy($feature, $this->body($request), $this->getUser()?->getUserIdentifier())]));
    }

    #[Route('/requests', name: 'api_platform_requests', methods: ['GET'])]
    public function requests(): JsonResponse
    {
        return $this->json(['requests' => $this->requests->listPending()]);
    }

    #[Route('/requests/{id}', name: 'api_platform_request_decide', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->manage->decide($id, $this->body($request), $this->getUser()?->getUserIdentifier())));
    }

    #[Route('/events', name: 'api_platform_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $tenant = trim((string) $request->query->get('tenant', ''));

        return $this->json(['events' => $this->repository->events($tenant !== '' ? $tenant : null, (int) $request->query->get('limit', 100))]);
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PlatformValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => '', 'message' => $e->getMessage()]]], 422);
        } catch (\OutOfBoundsException $e) {
            return $this->json(['error' => 'NOT_FOUND', 'code' => 'NOT_FOUND', 'message' => $e->getMessage()], 404);
        } catch (\DomainException $e) {
            return $this->json(['error' => 'POLICY_FORBIDDEN', 'code' => 'POLICY_FORBIDDEN', 'message' => $e->getMessage()], 409);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
