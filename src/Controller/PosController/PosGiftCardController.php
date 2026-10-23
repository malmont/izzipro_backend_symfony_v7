<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosIdempotencyService;
use App\UseCase\PosGiftCardUseCase\PosGiftCardUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cartes-cadeaux et avoirs (lot F9, 27/10/2026), routes propres au POS. Refus : {error, message, …contexte}. Idempotency-Key
 * (C5) acceptée sur la vente, l'annulation, l'ajustement, le remboursement de solde et le remplacement. Contrat : docs/caisse.md.
 */
class PosGiftCardController extends AbstractController
{
    public function __construct(private readonly PosGiftCardUseCase $cards, private readonly PosIdempotencyService $idempotency)
    {
    }

    /** {amount (cents), code? (carte pré-imprimée), paymentMethods [{type 1|2|3, amount (dollars), terminalPaymentId?}], cashRegisterId?} → 201 {giftCard {code…}, orderId, ticket} */
    #[Route('/api/pos/gift-cards', name: 'pos_gift_card_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $r = $this->cards->issue($this->user(), $this->body($request), $this->idempotency->currentKey(), $this->locale($request));
            $this->idempotency->attach('gift_card', $r['card']->getId(), $r['body'], 201);

            return $this->json($r['body'], 201);
        });
    }

    /** Solde et état d'une carte ou d'un avoir (consultation limitée et journalisée) */
    #[Route('/api/pos/gift-cards/{code}', name: 'pos_gift_card_lookup', methods: ['GET'])]
    public function lookup(string $code): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->cards->lookup($this->user(), $code)));
    }

    /** Annulation d'une carte vendue jamais utilisée : {terminalRefundId?, cashRegisterId?}, validation C3 gift_card_cancel */
    #[Route('/api/pos/gift-cards/{code}/cancel', name: 'pos_gift_card_cancel', methods: ['POST'])]
    public function cancel(Request $request, string $code): JsonResponse
    {
        return $this->guard(function () use ($request, $code) {
            $r = $this->cards->cancel($this->user(), $code, $this->body($request), $this->idempotency->currentKey(), $this->locale($request));
            $this->idempotency->attach('gift_card_update', $r['card']->getId(), $r['body'], 200);

            return $this->json($r['body']);
        });
    }

    /** Ajustement manuel du solde : {amount signé (cents), reason, cashRegisterId?}, validation C3 gift_card_adjust */
    #[Route('/api/pos/gift-cards/{code}/adjust', name: 'pos_gift_card_adjust', methods: ['POST'])]
    public function adjust(Request $request, string $code): JsonResponse
    {
        return $this->guard(function () use ($request, $code) {
            $body = $this->cards->adjust($this->user(), $code, $this->body($request));
            $this->idempotency->attach('gift_card_update', $body['giftCard']['id'], $body, 200);

            return $this->json($body);
        });
    }

    /** Remboursement du solde en espèces : de droit jusqu'au seuil du site, au-delà selon le réglage avec validation C3 */
    #[Route('/api/pos/gift-cards/{code}/cash-out', name: 'pos_gift_card_cash_out', methods: ['POST'])]
    public function cashOut(Request $request, string $code): JsonResponse
    {
        return $this->guard(function () use ($request, $code) {
            $body = $this->cards->cashOut($this->user(), $code, $this->body($request));
            $this->idempotency->attach('gift_card_update', $body['giftCard']['id'], $body, 200);

            return $this->json($body);
        });
    }

    /** Carte perdue : {orderId | barcode (IZ-C-<poste>-<n°>) | returnId, last4, cashRegisterId?} → 201 nouvelle carte (code affiché une fois), validation C3 gift_card_replace */
    #[Route('/api/pos/gift-cards/replace', name: 'pos_gift_card_replace', methods: ['POST'], priority: 1)]
    public function replace(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $r = $this->cards->replace($this->user(), $this->body($request));
            $this->idempotency->attach('gift_card', $r['card']->getId(), $r['body'], 201);

            return $this->json($r['body'], 201);
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
