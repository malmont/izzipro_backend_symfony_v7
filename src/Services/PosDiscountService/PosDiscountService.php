<?php

namespace App\Services\PosDiscountService;

use App\Dto\CartQuoteInputDto;
use App\Entity\Entreprise;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRoundingService;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\TenantEntityManagerProvider;

/**
 * Remises de caisse (F1, 24/10/2026) : réglages du site, contrôle des remises demandées (autorisées, motif, plafonds par
 * rôle, validation C3 action « discount »), devis de caisse remisé (POST /api/pos/quote). Le serveur est seule source de
 * vérité : prix nets, taxes sur le net, total et cashTotal.
 */
class PosDiscountService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CartQuoteCalculator $quotes,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly CashRoundingService $rounding,
        private readonly PosApprovalService $approvals,
    ) {
    }

    public function settings(): array
    {
        return self::settingsOf($this->entreprise());
    }

    /** Réglages des remises d'un site (statique : utilisable par PosSettingsService sans dépendance circulaire) */
    public static function settingsOf(?Entreprise $e): array
    {
        return [
            'enabled' => $e?->isPosDiscountEnabled() ?? true,
            'ticketEnabled' => $e?->isPosDiscountTicketEnabled() ?? true,
            'cashier' => ['maxPercent' => ($e?->getPosDiscountCashierMaxPercentBp() ?? 1000) / 100, 'maxAmount' => $e?->getPosDiscountCashierMaxAmount() ?? 2000],
            'manager' => ['maxPercent' => ($e?->getPosDiscountManagerMaxPercentBp() ?? 5000) / 100, 'maxAmount' => $e?->getPosDiscountManagerMaxAmount() ?? 20000],
            'reasonRequired' => $e?->isPosDiscountReasonRequired() ?? true,
            'reasons' => $e ? $e->discountReasons() : array_map(fn ($l) => ['code' => strtolower($l), 'label' => $l], Entreprise::DISCOUNT_REASONS_DEFAULT),
        ];
    }

    /**
     * Devis de caisse : articles (avec remises) + remise de ticket, taxes du magasin, cashTotal arrondi.
     *
     * @throws CashRegisterException QUOTE_INVALID, DISCOUNT_INVALID, DISCOUNT_NOT_ALLOWED, DISCOUNT_REASON_REQUIRED, DISCOUNT_REASON_INVALID
     */
    public function quote(array $items, ?array $ticketDiscount): array
    {
        $this->check($items, $ticketDiscount);
        try {
            $quote = $this->quotes->quote(CartQuoteInputDto::fromArray(['items' => $items, 'shippingAddress' => $this->taxAddress->forStore(), 'ticketDiscount' => $ticketDiscount], true));
        } catch (CartQuoteException $e) {
            throw new CashRegisterException(str_contains($e->getMessage(), 'remise') || str_contains($e->getMessage(), 'iscount') ? 'DISCOUNT_INVALID' : 'QUOTE_INVALID', $e->getMessage(), $e->getCode() === 404 ? 404 : 422, $e->errors);
        }
        $quote['discounts'] ??= ['lines' => 0, 'ticket' => null, 'total' => 0, 'gross' => $quote['subtotal']];
        $quote['cashTotal'] = $this->rounding->round((int) $quote['total']);

        return $quote;
    }

    /** Structure, autorisation du site et motifs des remises demandées (sans calcul) */
    public function check(array $items, ?array $ticketDiscount): void
    {
        if (!PosDiscountCalculator::hasDiscounts($items, $ticketDiscount)) {
            return;
        }
        $settings = $this->settings();
        if (!$settings['enabled']) {
            throw new CashRegisterException('DISCOUNT_NOT_ALLOWED', 'Les remises sont désactivées sur ce site', 409);
        }
        if ($ticketDiscount !== null && $ticketDiscount !== [] && !$settings['ticketEnabled']) {
            throw new CashRegisterException('DISCOUNT_NOT_ALLOWED', 'La remise sur le ticket est désactivée sur ce site', 409, [], ['scope' => 'ticket']);
        }
        try {
            $all = [];
            foreach ($items as $i => $item) {
                foreach (PosDiscountCalculator::list(is_array($item) ? ($item['discounts'] ?? null) : null, "items[$i].discounts") as $d) {
                    $all[] = $d;
                }
            }
            if ($ticketDiscount !== null && $ticketDiscount !== []) {
                $all[] = PosDiscountCalculator::one($ticketDiscount, 'ticketDiscount');
            }
        } catch (CartQuoteException $e) {
            throw new CashRegisterException('DISCOUNT_INVALID', $e->getMessage(), 422, $e->errors);
        }
        $codes = array_column($settings['reasons'], 'code');
        foreach ($all as $d) {
            if ($d['reasonCode'] !== null && !in_array($d['reasonCode'], $codes, true)) {
                throw new CashRegisterException('DISCOUNT_REASON_INVALID', 'Motif de remise inconnu : voir settings.discounts.reasons', 422, [], ['reasonCode' => $d['reasonCode'], 'reasons' => $codes]);
            }
            if ($settings['reasonRequired'] && $d['reasonCode'] === null && $d['reasonText'] === null) {
                throw new CashRegisterException('DISCOUNT_REASON_REQUIRED', 'Un motif est requis pour chaque remise (reasonCode ou reasonText)', 422);
            }
        }
    }

    /**
     * Plafonds par rôle et validation C3 (action discount, contexte {amount, percent, scope}) pour la remise totale d'une vente.
     * Administrateur : sans plafond ; responsable : plafond responsable ; caissier : plafond caissier, au-delà validation
     * d'un responsable qui porte le plafond au plafond responsable.
     *
     * @param array{lines: int, ticket: ?array, total: int, gross: int} $summary
     */
    public function authorize(User $user, array $summary): void
    {
        if ($summary['total'] <= 0) {
            return;
        }
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }
        $settings = $this->settings();
        $percent = $summary['gross'] > 0 ? round($summary['total'] * 100 / $summary['gross'], 2) : 0.0;
        $scope = $summary['lines'] > 0 && $summary['ticket'] ? 'mixed' : ($summary['ticket'] ? 'ticket' : 'line');
        $context = ['amount' => $summary['total'], 'percent' => (int) round($percent * 100), 'scope' => $scope];
        $manager = PosApprovalService::isManager($user);
        if (!$manager && !self::within($summary['total'], $percent, $settings['cashier'])) {
            // Au-delà du plafond caissier : validation d'un responsable (403 APPROVAL_REQUIRED sans jeton, jeton consommé sinon)
            $this->approvals->requireFor(PosApprovalService::DISCOUNT, $user, $context, $summary['total']);
        }
        if (!self::within($summary['total'], $percent, $settings['manager'])) {
            throw new CashRegisterException('DISCOUNT_EXCEEDS_LIMIT', 'Remise au-delà du plafond autorisé (responsable) : seul un administrateur peut l\'accorder', 403, [],
                ['limit' => $settings['manager'], 'amount' => $summary['total'], 'percent' => $percent]);
        }
    }

    public static function within(int $amount, float $percent, array $cap): bool
    {
        return $amount <= $cap['maxAmount'] && $percent <= $cap['maxPercent'] + 0.0001;
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }
}
