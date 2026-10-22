<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosIdempotencyService;
use App\UseCase\PosReceiptUseCase\PosReceiptUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Ticket numérique (F3, 24/10/2026) : réimpression, reçu par courriel, révocation du lien public. Refus : {error, message, …}. */
class PosReceiptController extends AbstractController
{
    public function __construct(private readonly PosReceiptUseCase $receipts, private readonly PosIdempotencyService $idempotency)
    {
    }

    /** ?barcode= | ?orderId=[&kind=] | ?kind=&number=&cashRegisterId= → bloc ticket complet, duplicate: true, journal RECEIPT_REPRINTED */
    #[Route('/api/pos/receipts', name: 'pos_receipt_reprint', methods: ['GET'])]
    public function reprint(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->receipts->reprint($this->user(), $this->receipts->find($request->query->all()), $this->locale($request))));
    }

    /** {barcode | orderId, email} → 200 {sent, barcode, to (masqué)} ; Idempotency-Key acceptée */
    #[Route('/api/pos/receipts/email', name: 'pos_receipt_email', methods: ['POST'])]
    public function email(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $body = $this->body($request);
            $receipt = $this->receipts->find($body);
            $result = $this->receipts->email($this->user(), $receipt, $body['email'] ?? null, $this->locale($request));
            $this->idempotency->attach('receipt_email', $receipt->getId(), $result, 200);

            return $this->json($result);
        });
    }

    /** {barcode | orderId} → les QR déjà imprimés de ce ticket cessent de fonctionner (responsable) */
    #[Route('/api/pos/receipts/revoke-link', name: 'pos_receipt_revoke_link', methods: ['POST'])]
    public function revokeLink(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_POS_MANAGER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Réservé au responsable de caisse ou à l\'administrateur');
        }

        return $this->guard(fn () => $this->json($this->receipts->revokeLink($this->user(), $this->receipts->find($this->body($request)))));
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
