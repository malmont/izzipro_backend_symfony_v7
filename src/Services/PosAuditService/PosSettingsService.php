<?php

namespace App\Services\PosAuditService;

use App\Entity\Entreprise;
use App\Services\TenantEntityManagerProvider;

/**
 * Réglages de caisse du site (chantier C3, 20/10/2026 ; fiche entreprise, panneau EasyAdmin « Caisse (POS) ») :
 * validation d'un responsable par action (seuil en cents : 0 = toujours, N = au-delà de N cents, null = jamais ;
 * booléen pour le tiroir et la fermeture sans comptage) et délai de verrouillage du POS (0 = jamais).
 */
class PosSettingsService
{
    public const PIN = ['minLength' => 4, 'maxLength' => 6, 'maxAttempts' => PosPinService::MAX_ATTEMPTS, 'lockMinutes' => PosPinService::LOCK_MINUTES];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly \App\Services\PosTerminalService\PosTerminalSettingsService $terminal)
    {
    }

    public function settings(): array
    {
        $e = $this->entreprise();
        $threshold = fn (?int $value) => ['required' => $value !== null, 'threshold' => $value];

        return [
            'lockAfterMinutes' => $e?->getPosLockMinutes() ?? 5,
            'approvals' => [
                'refund' => $threshold($e ? $e->getPosApprovalRefund() : 0),
                'withdrawal' => $threshold($e ? $e->getPosApprovalWithdrawal() : 0),
                'fundWithdrawal' => $threshold($e ? $e->getPosApprovalFundWithdrawal() : 0),
                'drawerOpen' => ['required' => $e?->isPosApprovalDrawerOpen() ?? true],
                'closeWithoutCount' => ['required' => $e?->isPosApprovalCloseWithoutCount() ?? true],
                'discount' => ['required' => false, 'threshold' => null], // réservé (chantier F1)
            ],
            'pin' => self::PIN,
            // Terminal de paiement Stripe (C4) : réglages utiles au POS (les commissions restent côté serveur)
            'terminal' => array_intersect_key($this->terminal->settings(), array_flip(['interac', 'cardWaitSeconds', 'customerCancel', 'orphanMinutes', 'authCancelMinutes', 'currency'])),
        ];
    }

    /** L'action exige-t-elle la validation d'un responsable (pour un utilisateur qui n'est ni responsable ni administrateur) ? */
    public function requiresApproval(string $action, ?int $amount = null): bool
    {
        $e = $this->entreprise();
        $threshold = fn (?int $value) => $value !== null && ($value === 0 || abs((int) $amount) > $value);

        return match ($action) {
            PosApprovalService::REFUND => $threshold($e ? $e->getPosApprovalRefund() : 0),
            PosApprovalService::WITHDRAWAL => $threshold($e ? $e->getPosApprovalWithdrawal() : 0),
            PosApprovalService::FUND_WITHDRAWAL => $threshold($e ? $e->getPosApprovalFundWithdrawal() : 0),
            PosApprovalService::DRAWER_OPEN => $e?->isPosApprovalDrawerOpen() ?? true,
            PosApprovalService::CLOSE_WITHOUT_COUNT => $e?->isPosApprovalCloseWithoutCount() ?? true,
            default => false,
        };
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }
}
