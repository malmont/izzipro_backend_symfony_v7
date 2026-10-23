<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosIdempotencyService;
use App\UseCase\PosCustomerUseCase\PosCustomerUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fiche client et fidélité (lot F8, 28/10/2026), routes propres au POS. Caissier : recherche, création, fiche, historique,
 * mise à jour, rattachement ; responsable : fusion, effacement (C3), export, ajustement de points (C3). Idempotency-Key (C5)
 * sur la création, la fusion, l'effacement et l'ajustement. Contrat : docs/caisse.md.
 */
class PosCustomerController extends AbstractController
{
    public function __construct(private readonly PosCustomerUseCase $customers, private readonly PosIdempotencyService $idempotency)
    {
    }

    /** ?q= téléphone | courriel | nom (3 caractères au moins) | code de fidélité → {query, kind, results [{source: pos | web, …}]} */
    #[Route('/api/pos/customers', name: 'pos_customer_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->search($this->user(), $request->query->get('q'))));
    }

    /** {firstName?, lastName?, phone?, email?, consents?: {loyalty, receipts, marketing, account_link}} → 201 fiche */
    #[Route('/api/pos/customers', name: 'pos_customer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $body = $this->customers->create($this->user(), $this->body($request));
            $this->idempotency->attach('customer', $body['id'], $body, 201);

            return $this->json($body, 201);
        });
    }

    #[Route('/api/pos/customers/{id}', name: 'pos_customer_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->get($this->user(), $id)));
    }

    #[Route('/api/pos/customers/{id}/history', name: 'pos_customer_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function history(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->history($this->user(), $id)));
    }

    #[Route('/api/pos/customers/{id}', name: 'pos_customer_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->update($this->user(), $id, $this->body($request))));
    }

    /** {userId} : rattachement à un compte web, consentement account_link exigé */
    #[Route('/api/pos/customers/{id}/link', name: 'pos_customer_link', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function link(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->link($this->user(), $id, $this->body($request))));
    }

    /** {intoId} : fusion de doublons (responsable) */
    #[Route('/api/pos/customers/{id}/merge', name: 'pos_customer_merge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function merge(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            $body = $this->customers->merge($this->user(), $id, $this->body($request));
            $this->idempotency->attach('customer_update', $body['id'], $body, 200);

            return $this->json($body);
        });
    }

    /** Effacement = anonymisation (responsable, validation C3 customer_erase) */
    #[Route('/api/pos/customers/{id}/erase', name: 'pos_customer_erase', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function erase(int $id): JsonResponse
    {
        return $this->guard(function () use ($id) {
            $body = $this->customers->erase($this->user(), $id);
            $this->idempotency->attach('customer_update', $body['id'], $body, 200);

            return $this->json($body);
        });
    }

    /** Droit d'accès (responsable) : export complet */
    #[Route('/api/pos/customers/{id}/export', name: 'pos_customer_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->export($this->user(), $id)));
    }

    /** {points (signé), reason, cashRegisterId?} : ajustement de points (responsable ou validation C3 loyalty_adjust) */
    #[Route('/api/pos/customers/{id}/loyalty/adjust', name: 'pos_loyalty_adjust', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adjustLoyalty(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            $body = $this->customers->adjustLoyalty($this->user(), $id, $this->body($request));
            $this->idempotency->attach('customer_update', $body['customer']['id'], $body, 200);

            return $this->json($body);
        });
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
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
        if (!$user instanceof User) {
            throw new CashRegisterException('UNAUTHENTICATED', 'Utilisateur non connecté', 401);
        }

        return $user;
    }
}
