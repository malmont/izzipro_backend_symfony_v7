<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosIdempotencyService;
use App\UseCase\PosTerminalUseCase\PosTerminalUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Terminal de paiement Stripe de la caisse (C4, 23/10/2026), routes propres au POS sous /api/pos/terminal. Le POS ne parle
 * jamais au lecteur : il demande le paiement ici, puis interroge son état (1 à 2 s). Refus : {error, message, …contexte}.
 * Idempotency-Key (C5) acceptée sur la création, l'annulation et le remboursement.
 */
class PosTerminalController extends AbstractController
{
    public function __construct(private readonly PosTerminalUseCase $terminal, private readonly PosIdempotencyService $idempotency)
    {
    }

    /** ?cashRegisterId= → {available, cashRegisterId, reader {id, label, simulated, status, error}, paymentMethods, currentPayment, simulationAllowed, settings} */
    #[Route('/api/pos/terminal', name: 'pos_terminal_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->terminal->status($request->query->get('cashRegisterId'))));
    }

    /** {cashRegisterId?, amount, items, otherPayments?} → 201 paiement envoyé au lecteur */
    #[Route('/api/pos/terminal/payments', name: 'pos_terminal_payment_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $payment = $this->terminal->create($this->user(), $this->body($request), $this->idempotency->currentKey());
            $body = $this->terminal->toArray($payment);
            $this->idempotency->attach('terminal_payment', $payment->getId(), $body, 201);

            return $this->json($body, 201);
        });
    }

    /** ?orphans=1&cashRegisterId= : paiements réussis sans vente (responsable, administrateur) */
    #[Route('/api/pos/terminal/payments', name: 'pos_terminal_payment_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyUnlessManager();

        return $this->guard(fn () => $this->json(['payments' => $this->terminal->orphans($request->query->get('cashRegisterId'))]));
    }

    /** État relu chez Stripe : {status: waiting_for_card | processing | succeeded | failed | canceled, captured, paymentMethodType, cardBrand, last4, failureCode, failureMessage, refund} */
    #[Route('/api/pos/terminal/payments/{id}', name: 'pos_terminal_payment_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->terminal->toArray($this->terminal->get($id, $this->user()))));
    }

    /** Annulation par le caissier : action du lecteur et intention annulées → 200 ; déjà débité → 409 PAYMENT_ALREADY_CAPTURED */
    #[Route('/api/pos/terminal/payments/{id}/cancel', name: 'pos_terminal_payment_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        return $this->guard(function () use ($id) {
            $payment = $this->terminal->cancel($id, $this->user());
            $body = $this->terminal->toArray($payment);
            $this->idempotency->attach('terminal_update', $payment->getId(), $body, 200);

            return $this->json($body);
        });
    }

    /** {amount?} : remboursement d'un paiement débité ; caissier : validation d'un responsable (C3, action refund) ; Interac présenté sur le lecteur, suivi par GET → 200 */
    #[Route('/api/pos/terminal/payments/{id}/refund', name: 'pos_terminal_payment_refund', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refund(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request) {
            $payment = $this->terminal->refund($id, $this->body($request), $this->user());
            $body = $this->terminal->toArray($payment);
            $this->idempotency->attach('terminal_update', $payment->getId(), $body, 200);

            return $this->json($body);
        });
    }

    /** Essais (clé de test, lecteur simulé) : {type: card_present | interac_present, cardNumber?} → carte présentée, état relu */
    #[Route('/api/pos/terminal/payments/{id}/simulate', name: 'pos_terminal_payment_simulate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function simulate(int $id, Request $request): JsonResponse
    {
        $this->denyUnlessManager();

        return $this->guard(fn () => $this->json($this->terminal->toArray($this->terminal->simulate($id, $this->body($request), $this->user()))));
    }

    private function denyUnlessManager(): void
    {
        if (!$this->isGranted('ROLE_POS_MANAGER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Réservé au responsable de caisse ou à l\'administrateur');
        }
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
