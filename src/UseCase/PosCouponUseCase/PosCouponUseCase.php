<?php

namespace App\UseCase\PosCouponUseCase;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosCouponService\PosCouponService;

/** Coupons de fabricant (F17, 01/11/2026) : validation des corps et orchestration pour /api/pos/coupons */
class PosCouponUseCase
{
    public function __construct(private readonly PosCouponService $coupons)
    {
    }

    /** POST /api/pos/coupons/parse {barcode} */
    public function parse(array $body): array
    {
        return $this->coupons->parse($body['barcode'] ?? null);
    }

    /** GET /api/pos/coupons/claims?from&to&manufacturer&status (responsable) */
    public function claims(User $user, array $query): array
    {
        $this->requireManager($user);

        return $this->coupons->claims($query);
    }

    public function claimsCsv(User $user, array $query): string
    {
        return $this->coupons->claimsCsv($this->claims($user, $query));
    }

    /** POST /api/pos/coupons/claims/mark {from, to, manufacturer?} (responsable) */
    public function markClaimed(User $user, array $body): array
    {
        $this->requireManager($user);

        return $this->coupons->markClaimed($body, $user);
    }

    private function requireManager(User $user): void
    {
        if (!PosApprovalService::isManager($user)) {
            throw new CashRegisterException('FORBIDDEN', 'Réclamation des coupons réservée au responsable de caisse', 403);
        }
    }
}
