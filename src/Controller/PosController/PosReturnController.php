<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosIdempotencyService;
use App\UseCase\PosReturnUseCase\PosReturnUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Retours partiels et échanges en caisse (F2, 24/10/2026), routes propres au POS. Refus : {error, message, …contexte}.
 * Idempotency-Key (C5) acceptée sur la création d'un retour et d'un échange.
 */
class PosReturnController extends AbstractController
{
    public function __construct(private readonly PosReturnUseCase $returns, private readonly PosIdempotencyService $idempotency)
    {
    }

    /** ?orderId= | ?barcode=IZ-T-1-123 | ?receipt=C1-123[&cashRegisterId=] → vente, lignes remboursables, éligibilité */
    #[Route('/api/pos/returns/lookup', name: 'pos_return_lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->returns->lookup($request->query->all())));
    }

    /** {orderId, lines: [{orderItemId, quantity}], reasonCode?, reasonText?, restock?, refund: {method, terminalRefundId?}, cashRegisterId?} → 201 */
    #[Route('/api/pos/returns', name: 'pos_return_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $return = $this->returns->createReturn($this->user(), $this->body($request), $this->idempotency->currentKey());
            $body = $this->returns->returnBody($return, $this->locale($request));
            $this->idempotency->attach('return', $return->getId(), $body, 201);

            return $this->json($body, 201);
        });
    }

    /** Simulation d'échange sans écriture (lecture) : {return: {orderId, lines}, sale: {items, ticketDiscount?, cashRegisterId?}} → montants */
    #[Route('/api/pos/exchanges/quote', name: 'pos_exchange_quote', methods: ['POST'])]
    public function exchangeQuote(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->returns->quoteExchange($this->body($request))));
    }

    /** {return: {…}, sale: {items, paymentMethods?, cashRegisterId?}} → 201 {return, sale} dans une seule transaction */
    #[Route('/api/pos/exchanges', name: 'pos_exchange_create', methods: ['POST'])]
    public function exchange(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $result = $this->returns->createExchange($this->user(), $this->body($request), $this->idempotency->currentKey());
            $body = $this->returns->exchangeBody($result['return'], $result['sale'], $this->locale($request));
            $this->idempotency->attach('exchange', $result['return']->getId(), $body, 201);

            return $this->json($body, 201);
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

    private function locale(Request $request): string
    {
        return preg_match('/^[a-z]{2}$/', (string) $request->query->get('locale', 'fr')) ? (string) $request->query->get('locale') : 'fr';
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
