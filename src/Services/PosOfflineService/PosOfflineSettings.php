<?php

namespace App\Services\PosOfflineService;

use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantEntityManagerProvider;

/**
 * Réglages du mode hors ligne (C5 partie 2, 25/10/2026) : désactivé par défaut, et désactivé d'office quand les taxes du
 * site viennent de Stripe Tax (la règle pos-offline-v1 reproduit le fournisseur « table »).
 */
class PosOfflineSettings
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TaxEngine $taxes,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly TenantCurrencyProvider $currency,
    ) {
    }

    public function settings(): array
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $wanted = $e?->isPosOfflineEnabled() ?? false;
        $provider = $wanted ? $this->taxProvider() : null;
        $enabled = $wanted && $provider === TaxEngine::PROVIDER_TABLE;

        return [
            'enabled' => $enabled,
            'reason' => $enabled ? null : ($wanted ? 'TAX_PROVIDER_STRIPE' : 'DISABLED'),
            'maxOfflineHours' => $e?->getPosOfflineMaxHours() ?? 24, 'maxQueuedSales' => $e?->getPosOfflineMaxQueuedSales() ?? 100,
            'maxQueuedAmount' => $e?->getPosOfflineMaxQueuedAmount() ?? 500000, 'priceTolerance' => $e?->getPosOfflinePriceTolerance() ?? 5,
            'packValidityHours' => $e?->getPosOfflinePackValidityHours() ?? 24, 'rule' => PosOfflineCalculator::RULE,
        ];
    }

    /** Fournisseur de taxes effectif du site (table | stripe), par un calcul témoin à l'adresse du magasin */
    public function taxProvider(): string
    {
        $raw = $this->taxAddress->forStore();
        $result = $this->taxes->compute($raw ? TaxEngine::normalizeAddress($raw) : null, [['amount' => 100, 'quantity' => 1, 'kind' => 'sale', 'reference' => 'probe']], 0, $this->currency->code());

        return (string) ($result['provider'] ?? TaxEngine::PROVIDER_TABLE);
    }
}
