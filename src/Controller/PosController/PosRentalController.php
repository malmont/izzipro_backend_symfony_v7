<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\MediaUrlResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\UseCase\PosRentalUseCase\PosRentalUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Location au comptoir (09/11/2026), caisse : catalogue des locations (en ligne seulement), cautions (encaissement après coup, liste,
 * restitution avec retenue validée), locations en cours et retour du matériel. Contrat : docs/caisse.md « Location au comptoir et caution ».
 */
class PosRentalController extends AbstractController
{
    public function __construct(private readonly PosRentalUseCase $rentals, private readonly MediaUrlResolver $media)
    {
    }

    #[Route('/api/pos/rentals', name: 'pos_rentals', methods: ['GET'])]
    public function catalogue(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->catalogue($this->media->getProductsBaseUrl($request->getSchemeAndHttpHost()), (string) $request->query->get('locale', 'fr'))));
    }

    #[Route('/api/pos/rentals/ongoing', name: 'pos_rentals_ongoing', methods: ['GET'])]
    public function ongoing(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->ongoing($request->query->get('cashRegisterId'))));
    }

    #[Route('/api/pos/rentals/deposits', name: 'pos_rentals_deposits', methods: ['GET'])]
    public function deposits(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->deposits($request->query->get('status'), $request->query->get('cashRegisterId'))));
    }

    #[Route('/api/pos/rentals/deposits', name: 'pos_rentals_deposit_collect', methods: ['POST'])]
    public function collect(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->collect($this->user(), $this->body($request)), 201));
    }

    #[Route('/api/pos/rentals/deposits/{id}', name: 'pos_rentals_deposit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function deposit(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->deposit($id)));
    }

    #[Route('/api/pos/rentals/deposits/{id}/return', name: 'pos_rentals_deposit_return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function returnDeposit(int $id, Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->returnDeposit($id, $this->user(), $this->body($request), $request->headers->get('Idempotency-Key'), $request->headers->get(PosApprovalService::HEADER))));
    }

    #[Route('/api/pos/rentals/bookings/{id}/return', name: 'pos_rentals_booking_return', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function returnBooking(int $id, Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->rentals->returnBooking($id, $this->user(), $this->body($request))));
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
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
