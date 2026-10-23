<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosSettingsService;
use App\UseCase\PosAuditUseCase\PosPinUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PIN, déverrouillage, connexion par PIN, réglages et validation d'un responsable (chantier C3, 20/10/2026). pin-login
 * et users sont des routes de connexion (hors pare-feu JWT, X-Pos-Terminal exigé) ; les autres exigent un rôle caisse
 * (access_control). Contrat : docs/caisse.md.
 */
class PosPinController extends AbstractController
{
    public function __construct(private readonly PosPinUseCase $pins, private readonly PosApprovalService $approvals, private readonly PosSettingsService $settings)
    {
    }

    #[Route('/api/pos/pin', name: 'pos_pin_set', methods: ['POST'])]
    public function setPin(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $data = $this->body($request);
            $this->pins->setPin($this->user(), $data['password'] ?? null, $data['newPin'] ?? null);

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    #[Route('/api/pos/unlock', name: 'pos_unlock', methods: ['POST'])]
    public function unlock(Request $request): Response
    {
        return $this->guard(fn () => new JsonResponse($this->pins->unlock($this->user(), $this->body($request)['pin'] ?? null)));
    }

    #[Route('/api/pos/users', name: 'pos_users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->guard(fn () => new JsonResponse($this->pins->users()));
    }

    #[Route('/api/pos/pin-login', name: 'pos_pin_login', methods: ['POST'])]
    public function pinLogin(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $data = $this->body($request);

            return $this->pins->pinLogin($data['userId'] ?? null, $data['pin'] ?? null, $request);
        });
    }

    /** Réglages de caisse du site : verrouillage (0 = jamais), validations, règles du PIN */
    #[Route('/api/pos/settings', name: 'pos_settings', methods: ['GET'])]
    public function settings(): Response
    {
        return new JsonResponse($this->settings->settings());
    }

    /** {action, managerId, pin, context} ou {actions: [{action, context}, …], managerId, pin} (jeton groupé, P2-a) → 201 {approvalToken, expiresAt} ; jeton à rejouer dans X-Pos-Approval */
    #[Route('/api/pos/approvals', name: 'pos_approvals', methods: ['POST'])]
    public function approve(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $data = $this->body($request);

            return new JsonResponse($this->approvals->grant($data['action'] ?? null, $data['managerId'] ?? null, $data['pin'] ?? null, $data['context'] ?? null, $this->user(), $data['actions'] ?? null), 201);
        });
    }

    private function guard(callable $action): Response
    {
        try {
            return $action();
        } catch (CashRegisterException $e) {
            $response = new JsonResponse($e->toBody(), $e->status);
            if (isset($e->context['retryAfter'])) {
                $response->headers->set('Retry-After', (string) $e->context['retryAfter']);
            }

            return $response;
        }
    }

    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function user(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new CashRegisterException('UNAUTHENTICATED', 'Utilisateur non connecté', 401);
    }
}
