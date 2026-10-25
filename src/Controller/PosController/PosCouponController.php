<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\UseCase\PosCouponUseCase\PosCouponUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Coupons de fabricant (F17, 01/11/2026) : lecture d'un code-barres, rapport des coupons à réclamer par fabricant et période
 * (JSON et CSV), marquage « réclamé » par lot. Les coupons eux-mêmes se présentent dans la vente (parts type 6 de
 * create-multi-payment) et dans le devis (POST /api/pos/quote, coupons[]). Refus : {error, message, errors?, …contexte}.
 */
class PosCouponController extends AbstractController
{
    public function __construct(private readonly PosCouponUseCase $coupons)
    {
    }

    /** {barcode} → champs reconnus : {format, companyPrefix, offerCode, saveValue, amount, amountReliable, expiresAt, serial, expired, alreadyUsed, …} ; 422 COUPON_BARCODE_INVALID */
    #[Route('/api/pos/coupons/parse', name: 'pos_coupon_parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->coupons->parse($this->body($request))));
    }

    /** ?from&to&manufacturer&status=to_claim|claimed|returned_after_claim|voided|all → rapport par fabricant (responsable) */
    #[Route('/api/pos/coupons/claims', name: 'pos_coupon_claims', methods: ['GET'])]
    public function claims(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->coupons->claims($this->user(), $request->query->all())));
    }

    /** Même rapport en CSV (« ; », virgule décimale, BOM), une ligne par coupon avec les fractions de taxe */
    #[Route('/api/pos/coupons/claims.csv', name: 'pos_coupon_claims_csv', methods: ['GET'])]
    public function claimsCsv(Request $request): Response
    {
        try {
            $csv = $this->coupons->claimsCsv($this->user(), $request->query->all());
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
        $q = $request->query;

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="coupons-fabricant_%s_%s.csv"', $q->get('from', 'debut'), $q->get('to', 'fin')), 'Cache-Control' => 'no-store']);
    }

    /** {from, to, manufacturer?} → coupons à réclamer de la période passés en « réclamé », lot daté (responsable) ; 409 NOTHING_TO_CLAIM */
    #[Route('/api/pos/coupons/claims/mark', name: 'pos_coupon_claims_mark', methods: ['POST'])]
    public function markClaimed(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->coupons->markClaimed($this->user(), $this->body($request))));
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
