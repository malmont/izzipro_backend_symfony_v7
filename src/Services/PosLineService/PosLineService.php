<?php

namespace App\Services\PosLineService;

use App\Entity\Entreprise;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\TenantEntityManagerProvider;

/**
 * Article libre et prix manuel (F7, lot P2-a, 26/10/2026), chemins de caisse seulement.
 *  - Article libre : items[].custom {label, unitPrice (cents), taxable?} sans productVariantId → ligne de commande sans variante
 *    (libellé, taxable ou non), aucun mouvement de stock.
 *  - Prix manuel : items[].priceOverride {unitPrice (cents), reasonCode?, reasonText?} sur un article du catalogue → le prix
 *    catalogue est gardé (catalogUnitPrice) et le ticket montre les deux ; distinct d'une remise F1 (qui s'applique après).
 * Réglages du site (settings.customItems, settings.priceOverride) ; au-delà du montant maximal, validation d'un responsable
 * (C3) : action custom_item {amount} (total des articles libres), price_override {amount} (somme des baisses). Une hausse
 * de prix n'est jamais soumise à validation. Journal : PRICE_CHANGED par ligne, articles libres dans SALE.
 */
class PosLineService
{
    public const CUSTOM_ITEM = 'custom_item';
    public const PRICE_OVERRIDE = 'price_override';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function settings(): array
    {
        return self::settingsOf($this->entreprise());
    }

    /** Réglages d'un site (statique : utilisable par PosSettingsService sans dépendance circulaire) */
    public static function settingsOf(?Entreprise $e): array
    {
        return [
            'customItems' => ['enabled' => $e?->isPosCustomItemEnabled() ?? false, 'maxAmount' => $e?->getPosCustomItemMaxAmount(), 'labelRequired' => true],
            'priceOverride' => ['enabled' => $e?->isPosPriceOverrideEnabled() ?? false, 'maxAmount' => $e?->getPosPriceOverrideMaxAmount(),
                'reasonRequired' => $e?->isPosPriceOverrideReasonRequired() ?? true,
                'reasons' => $e ? $e->priceOverrideReasons() : array_map(fn ($l) => ['code' => strtolower($l), 'label' => $l], Entreprise::PRICE_OVERRIDE_REASONS_DEFAULT)],
        ];
    }

    /** La demande contient-elle un article libre ou un prix manuel ? */
    public static function hasLines(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && (isset($item['custom']) || isset($item['priceOverride']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Autorisation du site et motifs (sans calcul) : 409 CUSTOM_ITEM_NOT_ALLOWED / PRICE_OVERRIDE_NOT_ALLOWED,
     * 422 PRICE_OVERRIDE_REASON_REQUIRED / PRICE_OVERRIDE_REASON_INVALID. La structure est vérifiée par le devis (422 QUOTE_INVALID).
     */
    public function check(array $items): void
    {
        if (!self::hasLines($items)) {
            return;
        }
        $settings = $this->settings();
        $codes = array_column($settings['priceOverride']['reasons'], 'code');
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['custom']) && !$settings['customItems']['enabled']) {
                throw new CashRegisterException('CUSTOM_ITEM_NOT_ALLOWED', 'L\'article libre est désactivé sur ce site', 409);
            }
            if (isset($item['priceOverride'])) {
                if (!$settings['priceOverride']['enabled']) {
                    throw new CashRegisterException('PRICE_OVERRIDE_NOT_ALLOWED', 'Le prix manuel est désactivé sur ce site', 409);
                }
                $o = is_array($item['priceOverride']) ? $item['priceOverride'] : [];
                $code = isset($o['reasonCode']) && is_string($o['reasonCode']) && $o['reasonCode'] !== '' ? $o['reasonCode'] : null;
                $text = isset($o['reasonText']) && is_string($o['reasonText']) && trim($o['reasonText']) !== '' ? trim($o['reasonText']) : null;
                if ($code !== null && !in_array($code, $codes, true)) {
                    throw new CashRegisterException('PRICE_OVERRIDE_REASON_INVALID', 'Motif de prix manuel inconnu : voir settings.priceOverride.reasons', 422, [], ['reasonCode' => $code, 'reasons' => $codes]);
                }
                if ($settings['priceOverride']['reasonRequired'] && $code === null && $text === null) {
                    throw new CashRegisterException('PRICE_OVERRIDE_REASON_REQUIRED', 'Un motif est requis pour chaque prix manuel (reasonCode ou reasonText)', 422);
                }
            }
        }
    }

    /**
     * Validations à demander pour une vente (C3) : custom_item {amount} au-delà du montant maximal des articles libres,
     * price_override {amount} au-delà de la baisse maximale. Un responsable ou un administrateur n'en a jamais besoin.
     *
     * @param array $quote devis (CartQuoteCalculator) avec customItems / priceOverrides
     * @return list<array{action: string, context: array<string, int>, amount: int}>
     */
    public function demands(User $user, array $quote): array
    {
        if (PosApprovalService::isManager($user)) {
            return [];
        }
        $settings = $this->settings();
        $demands = [];
        $custom = (int) ($quote['customItems']['total'] ?? 0);
        if (($quote['customItems']['count'] ?? 0) > 0 && self::beyond($custom, $settings['customItems']['maxAmount'])) {
            $demands[] = ['action' => self::CUSTOM_ITEM, 'context' => ['amount' => $custom], 'amount' => $custom];
        }
        $reduction = (int) ($quote['priceOverrides']['reduction'] ?? 0);
        if (($quote['priceOverrides']['count'] ?? 0) > 0 && $reduction > 0 && self::beyond($reduction, $settings['priceOverride']['maxAmount'])) {
            $demands[] = ['action' => self::PRICE_OVERRIDE, 'context' => ['amount' => $reduction], 'amount' => $reduction];
        }

        return $demands;
    }

    /** Montant au-delà du maximum sans validation (null = jamais, 0 = toujours) */
    public static function beyond(int $amount, ?int $max): bool
    {
        return $max !== null && ($max === 0 || $amount > $max);
    }

    /**
     * Ligne d'article libre validée : {name, unitPrice, custom {label, taxable}} ; null et erreur ajoutée sinon.
     *
     * @param list<array{path: string, message: string}> $errors
     */
    public static function customLine(array $custom, string $path, array &$errors): ?array
    {
        $label = isset($custom['label']) && is_string($custom['label']) ? trim($custom['label']) : '';
        if ($label === '' || mb_strlen($label) > 120) {
            $errors[] = ['path' => "$path.custom.label", 'message' => 'libellé requis (120 caractères au plus)'];

            return null;
        }
        $price = $custom['unitPrice'] ?? null;
        if (!is_numeric($price) || (int) $price < 0 || (int) $price != $price) {
            $errors[] = ['path' => "$path.custom.unitPrice", 'message' => 'prix unitaire en cents (entier positif ou nul) attendu'];

            return null;
        }
        $taxable = $custom['taxable'] ?? true;
        if (!is_bool($taxable)) {
            $errors[] = ['path' => "$path.custom.taxable", 'message' => 'booléen attendu'];

            return null;
        }

        return ['name' => $label, 'unitPrice' => (int) $price, 'custom' => ['label' => $label, 'taxable' => $taxable]];
    }

    /**
     * Prix manuel validé : {unitPrice, reasonCode, reasonText, difference} (difference = (catalogue − manuel) × quantité,
     * positive pour une baisse) ; null et erreur ajoutée sinon.
     */
    public static function priceOverride(array $override, int $catalogUnitPrice, int $quantity, string $path, array &$errors): ?array
    {
        $price = $override['unitPrice'] ?? null;
        if (!is_numeric($price) || (int) $price < 0 || (int) $price != $price) {
            $errors[] = ['path' => "$path.priceOverride.unitPrice", 'message' => 'prix unitaire en cents (entier positif ou nul) attendu'];

            return null;
        }
        $code = isset($override['reasonCode']) && is_string($override['reasonCode']) && $override['reasonCode'] !== '' ? mb_substr($override['reasonCode'], 0, 40) : null;
        $text = isset($override['reasonText']) && is_string($override['reasonText']) && trim($override['reasonText']) !== '' ? mb_substr(trim($override['reasonText']), 0, 255) : null;

        return ['unitPrice' => (int) $price, 'reasonCode' => $code, 'reasonText' => $text, 'difference' => ($catalogUnitPrice - (int) $price) * $quantity];
    }

    /**
     * Résumés d'un devis : customItems {count, total} et priceOverrides {count, catalogTotal, total, difference, reduction},
     * présents seulement si la vente contient de telles lignes (les réponses existantes restent identiques).
     */
    public static function summary(array $lines): array
    {
        $custom = ['count' => 0, 'total' => 0];
        $overrides = ['count' => 0, 'catalogTotal' => 0, 'total' => 0, 'difference' => 0, 'reduction' => 0];
        foreach ($lines as $line) {
            if (isset($line['custom'])) {
                $custom['count']++;
                $custom['total'] += (int) $line['total'];
            }
            if (isset($line['priceOverride'])) {
                $qty = (int) $line['quantity'];
                $overrides['count']++;
                $overrides['catalogTotal'] += (int) $line['catalogUnitPrice'] * $qty;
                $overrides['total'] += (int) $line['unitPrice'] * $qty;
                $overrides['difference'] += (int) $line['priceOverride']['difference'];
                $overrides['reduction'] += max(0, (int) $line['priceOverride']['difference']);
            }
        }

        return ($custom['count'] > 0 ? ['customItems' => $custom] : []) + ($overrides['count'] > 0 ? ['priceOverrides' => $overrides] : []);
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }
}
