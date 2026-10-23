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
    /** Règles du PIN par défaut (lot R1 : modifiables par site, voir pinRules()) */
    public const PIN = ['minLength' => 4, 'maxLength' => 6, 'maxAttempts' => PosPinService::MAX_ATTEMPTS, 'lockMinutes' => PosPinService::LOCK_MINUTES];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly \App\Services\PosTerminalService\PosTerminalSettingsService $terminal, private readonly \App\Services\PosOfflineService\PosOfflineSettings $offline)
    {
    }

    public function settings(): array
    {
        $e = $this->entreprise();
        $threshold = fn (?int $value) => ['required' => $value !== null, 'threshold' => $value];
        $discounts = \App\Services\PosDiscountService\PosDiscountService::settingsOf($e);
        $lines = \App\Services\PosLineService\PosLineService::settingsOf($e); // article libre et prix manuel (F7, 26/10/2026)

        return [
            'lockAfterMinutes' => $e?->getPosLockMinutes() ?? 5,
            'approvals' => [
                'refund' => $threshold($e ? $e->getPosApprovalRefund() : 0),
                'withdrawal' => $threshold($e ? $e->getPosApprovalWithdrawal() : 0),
                'fundWithdrawal' => $threshold($e ? $e->getPosApprovalFundWithdrawal() : 0),
                'drawerOpen' => ['required' => $e?->isPosApprovalDrawerOpen() ?? true],
                'closeWithoutCount' => ['required' => $e?->isPosApprovalCloseWithoutCount() ?? true],
                // Remises (F1) : validation d'un responsable au-delà du plafond caissier (montant en cents ou pourcentage)
                'discount' => ['required' => $discounts['enabled'], 'threshold' => $discounts['cashier']['maxAmount'], 'maxPercent' => $discounts['cashier']['maxPercent']],
                'return' => $threshold($e ? $e->effectivePosApprovalReturn() : 0), // retour partiel ou échange (F2) : seuil du remboursement par défaut
                // Article libre et prix manuel (F7) : au-delà du montant maximal (null = jamais, 0 = toujours)
                'customItem' => ['required' => $lines['customItems']['enabled'] && $lines['customItems']['maxAmount'] !== null, 'threshold' => $lines['customItems']['maxAmount']],
                // Cartes-cadeaux (F9) : toujours un responsable
                'giftCardCancel' => ['required' => true], 'giftCardAdjust' => ['required' => true], 'giftCardReplace' => ['required' => true],
                'priceOverride' => ['required' => $lines['priceOverride']['enabled'] && $lines['priceOverride']['maxAmount'] !== null, 'threshold' => $lines['priceOverride']['maxAmount']],
            ],
            'pin' => $this->pinRules(),
            // Lot R1 (23/10/2026) : réglages par site, défauts = comportement d'avant (ajouts seulement)
            'cashFund' => ['openingSuggested' => $e?->getPosOpeningFundSuggested(), 'closingKeptSuggested' => $e?->getPosClosingKeptFundSuggested()],
            'paymentMethods' => $this->paymentMethods(),
            'receipt' => ['header' => $e?->getPosReceiptHeader(), 'footer' => $e?->getPosReceiptFooter(), 'showTaxNumbers' => $e?->isPosReceiptShowTaxNumbers() ?? true,
                // Ticket numérique (F3, 24/10/2026)
                'emailEnabled' => $e?->isPosReceiptEmailEnabled() ?? true, 'qrEnabled' => $e?->isPosReceiptQrEnabled() ?? true, 'linkDays' => $e?->getPosReceiptLinkDays() ?? 90, 'qrPrintDefault' => $e?->isPosReceiptQrPrintDefault() ?? true],
            'heldCarts' => ['max' => $e?->getPosHeldCartsMax() ?? 10, 'alertHours' => $e?->getPosHeldCartsAlertHours() ?? 24],
            'cashAlertThreshold' => $e?->getPosCashAlertThreshold(),
            'terminalRequired' => $e?->isPosTerminalHeaderRequired() ?? false,
            // Retours partiels et échanges (F2, 24/10/2026)
            'discounts' => $discounts, // remises de caisse (F1, 24/10/2026)
            'customItems' => $lines['customItems'], // article libre (F7, 26/10/2026)
            'priceOverride' => $lines['priceOverride'], // prix manuel (F7, 26/10/2026)
            'giftCards' => \App\Services\PosGiftCardService\PosGiftCardService::settingsOf($e), // cartes-cadeaux et avoirs (F9, 27/10/2026)
            'offline' => $this->offline->settings(), // mode hors ligne (C5 partie 2, 25/10/2026)
            'returns' => ['maxDays' => $e?->getPosReturnMaxDays(), 'withoutReceipt' => $e?->isPosReturnWithoutReceipt() ?? false, 'restockDefault' => $e?->isPosReturnRestockDefault() ?? true,
                'cashRefundForCardSale' => $e?->isPosReturnCashForCardSale() ?? false, 'reasons' => $e ? $e->returnReasons() : []],
            // Terminal de paiement Stripe (C4) : réglages utiles au POS (les commissions restent côté serveur)
            'terminal' => array_intersect_key($this->terminal->settings(), array_flip(['interac', 'cardWaitSeconds', 'customerCancel', 'orphanMinutes', 'authCancelMinutes', 'currency'])),
        ];
    }

    /** @return array{minLength: int, maxLength: int, maxAttempts: int, lockMinutes: int} règles du PIN du site (R1) */
    public function pinRules(): array
    {
        $e = $this->entreprise();

        return $e ? ['minLength' => $e->getPosPinMinLength(), 'maxLength' => $e->getPosPinMaxLength(), 'maxAttempts' => $e->getPosPinMaxAttempts(), 'lockMinutes' => $e->getPosPinLockMinutes()] : self::PIN;
    }

    /** @return array{cash: bool, declaredCard: bool, paypal: bool, multiPayment: bool, declaredCardBlockedWithTerminal: bool} */
    public function paymentMethods(): array
    {
        $e = $this->entreprise();

        return ['cash' => $e?->isPosPayCash() ?? true, 'declaredCard' => $e?->isPosPayDeclaredCard() ?? true, 'paypal' => $e?->isPosPayPaypal() ?? true,
            'multiPayment' => $e?->isPosPayMulti() ?? true, 'declaredCardBlockedWithTerminal' => $e?->isPosDeclaredCardBlockedWithTerminal() ?? false];
    }

    /**
     * Vente en caisse (R1) : refus des moyens désactivés sur le site. Types : 1 carte (déclarée sans terminalPaymentId),
     * 2 espèces, 3 PayPal ; plusieurs parts = multi-paiement.
     *
     * @param list<\App\Dto\PaymentMethodDTO> $methods
     */
    public function checkPaymentMethods(array $methods, \App\Entity\CashRegister $register): void
    {
        $allowed = $this->paymentMethods();
        if (count($methods) > 1 && !$allowed['multiPayment']) {
            throw new \App\Services\CaisseService\CashRegisterException('MULTI_PAYMENT_DISABLED', 'Le paiement en plusieurs parts est désactivé sur ce site', 422);
        }
        foreach ($methods as $method) {
            $type = $method->getType();
            if ($type === \App\Dto\PaymentMethodDTO::GIFT_CARD || $type === \App\Dto\PaymentMethodDTO::STORE_CREDIT) { // F9 : vérifiés et débités par PosGiftCardService
                continue;
            }
            $declaredCard = $type === 1 && $method->getTerminalPaymentId() === null;
            $disabled = match ($type) { 2 => !$allowed['cash'], 3 => !$allowed['paypal'], 1 => $declaredCard && !$allowed['declaredCard'], default => false };
            if ($disabled) {
                throw new \App\Services\CaisseService\CashRegisterException('PAYMENT_METHOD_DISABLED', 'Ce moyen de paiement est désactivé sur ce site', 422, [], ['type' => $type]);
            }
            if ($declaredCard && $allowed['declaredCardBlockedWithTerminal'] && $register->hasTerminal()) {
                throw new \App\Services\CaisseService\CashRegisterException('DECLARED_CARD_BLOCKED', 'Carte déclarée refusée : ce poste a un terminal de paiement, utilisez-le', 422, [], ['cashRegisterId' => $register->getId()]);
            }
        }
    }

    /** L'en-tête X-Pos-Terminal est-il exigé sur les routes de caisse de ce site (R1, désactivé par défaut) ? */
    public function terminalRequired(): bool
    {
        return $this->entreprise()?->isPosTerminalHeaderRequired() ?? false;
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
            PosApprovalService::RETURN => $threshold($e ? $e->effectivePosApprovalReturn() : 0),
            PosApprovalService::DISCOUNT => $e?->isPosDiscountEnabled() ?? true, // plafonds par rôle dans PosDiscountService
            PosApprovalService::OFFLINE_FORCE => true, // toujours un responsable (C5 partie 2)
            // Article libre et prix manuel (F7) : au-delà du montant maximal du site (null = jamais, 0 = toujours)
            PosApprovalService::CUSTOM_ITEM => ($e?->isPosCustomItemEnabled() ?? false) && \App\Services\PosLineService\PosLineService::beyond((int) $amount, $e?->getPosCustomItemMaxAmount()),
            PosApprovalService::PRICE_OVERRIDE => ($e?->isPosPriceOverrideEnabled() ?? false) && \App\Services\PosLineService\PosLineService::beyond((int) $amount, $e?->getPosPriceOverrideMaxAmount()),
            // Cartes-cadeaux (F9) : annulation, ajustement et remplacement toujours validés par un responsable
            PosApprovalService::GIFT_CARD_CANCEL, PosApprovalService::GIFT_CARD_ADJUST, PosApprovalService::GIFT_CARD_REPLACE => true,
            default => false,
        };
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }
}
