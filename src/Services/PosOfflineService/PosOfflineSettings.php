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
        private readonly ?\Symfony\Component\HttpFoundation\RequestStack $requests = null,
    ) {
    }

    /** La requête courante annonce-t-elle la règle hors ligne v5 (en-tête X-Pos-Offline-Rules) ? */
    public function announcesV5(): bool
    {
        return str_contains(strtolower((string) $this->requests?->getCurrentRequest()?->headers->get('X-Pos-Offline-Rules', '')), 'v5');
    }

    /** Site qui exige la règle v5 (PAYS-1c) : prix TTC et un taux applicable non multiple de 0,01 % (TVQ 9,975 %) */
    public function requiresV5(): bool
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $raw = $this->taxAddress->forStore();
        $address = $raw ? TaxEngine::normalizeAddress($raw) : null;

        return PosOfflineCalculator::requiresV5($e?->isPricesIncludeTax() ?? false, $address ? array_map(fn ($t) => ['rate' => (float) $t->getRate()], $this->taxes->applicableTaxes($address)) : []);
    }

    /** @param ?bool $v5 la tablette connaît la règle v5 (null : selon l'en-tête de la requête courante) */
    public function settings(?bool $v5 = null): array
    {
        $v5 ??= $this->announcesV5();
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $wanted = $e?->isPosOfflineEnabled() ?? false;
        $provider = $wanted ? $this->taxProvider() : null;
        $enabled = $wanted && $provider === TaxEngine::PROVIDER_TABLE;
        if ($enabled && !$v5 && $this->requiresV5()) { // PAYS-1c : jamais un calcul v3 faux ; la caisse à jour (v5) vend hors ligne

            return ['enabled' => false, 'reason' => 'RULE_V5_REQUIRED'] + $this->limits($e, false);
        }

        return [
            'enabled' => $enabled,
            'reason' => $enabled ? null : ($wanted ? 'TAX_PROVIDER_STRIPE' : 'DISABLED'),
        ] + $this->limits($e, $v5);
    }

    private function limits(?Entreprise $e, bool $v5): array
    {
        return [
            'maxOfflineHours' => $e?->getPosOfflineMaxHours() ?? 24, 'maxQueuedSales' => $e?->getPosOfflineMaxQueuedSales() ?? 100,
            'maxQueuedAmount' => $e?->getPosOfflineMaxQueuedAmount() ?? 500000, 'priceTolerance' => $e?->getPosOfflinePriceTolerance() ?? 5,
            'packValidityHours' => $e?->getPosOfflinePackValidityHours() ?? 24, 'rule' => $v5 ? PosOfflineCalculator::RULE_V5 : PosOfflineCalculator::RULE,
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
