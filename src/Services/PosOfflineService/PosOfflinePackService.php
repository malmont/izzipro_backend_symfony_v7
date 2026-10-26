<?php

namespace App\Services\PosOfflineService;

use App\Entity\CashRegister;
use App\Entity\Entreprise;
use App\Entity\PaymentMethod;
use App\Entity\PosOfflinePack;
use App\Entity\Product;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\CashRoundingService;
use App\Services\CaisseService\DenominationService;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\OrderService\TaxEngine;
use App\Services\PosAuditService\PosSettingsService;
use App\Services\PosDiscountService\PosDiscountService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * Pack hors ligne (C5 partie 2, 25/10/2026) : tout ce que la tablette doit connaître pour vendre en espèces sans réseau,
 * avec la règle de calcul pos-offline-v1. Chaque pack émis est conservé (prix, taxes, arrondi) sous sa version pour
 * recalculer les ventes hors ligne aux conditions que la tablette connaissait.
 */
class PosOfflinePackService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantCurrencyProvider $currency,
        private readonly CashRoundingService $rounding,
        private readonly DenominationService $denominations,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly TaxEngine $taxes,
        private readonly PosSettingsService $settings,
        private readonly PosOfflineSettings $offline,
        private readonly \App\Services\PosFavoriteService\PosFavoriteService $favorites, // grille de favoris (F10)
    ) {
    }

    /** Réglages du mode hors ligne (PosOfflineSettings) */
    public function settings(): array
    {
        return $this->offline->settings();
    }

    /** Pack courant : rendu au POS, instantané conservé sous sa version (une version par contenu : même ETag → même version) */
    public function build(?CashRegister $register = null): array
    {
        $em = $this->emProvider->getEntityManager();
        $e = $em->getRepository(Entreprise::class)->findOneBy([]);
        $settings = $this->settings();
        $address = $this->storeAddress();
        $taxes = $address ? array_map(fn ($t) => ['id' => (int) $t->getId(), 'label' => (string) $t->getName(), 'rate' => (float) $t->getRate()], $this->taxes->applicableTaxes($address)) : [];
        $step = $this->rounding->step();
        $products = [];
        $prices = [];
        foreach ($em->getRepository(Product::class)->findBy(['isPos' => true], ['id' => 'ASC']) as $product) {
            if (!$product->isSaleEnabled() || $product->isRentalEnabled()) {
                continue;
            }
            $variants = [];
            foreach ($product->getVariants() as $variant) {
                $variants[] = [
                    'id' => (int) $variant->getId(), 'label' => trim(implode(' / ', array_filter([$variant->getColor()?->getName(), $variant->getSize()?->getName()]))) ?: null,
                    'price' => $variant->getPrice(), 'barcode' => $variant->getBarcode(), 'stock' => $variant->getStockQuantity(),
                    'customizable' => !$variant->getProductCustomizationImages()->isEmpty(),
                ];
                $prices[(int) $variant->getId()] = ['unit' => (int) round((float) ($variant->getPrice() ?? $product->getPrice())), 'special' => $product->getSpecialPrice() !== null && $product->getSpecialPrice() > 0 ? (int) round((float) $product->getSpecialPrice()) : null,
                    'from' => $product->getSpecialPriceFrom()?->format(\DateTimeInterface::ATOM), 'to' => $product->getSpecialPriceTo()?->format(\DateTimeInterface::ATOM), 'variantPrice' => $variant->getPrice() !== null];
            }
            $products[] = [
                'id' => (int) $product->getId(), 'code' => $product->getCode(), 'barcode' => $product->getBarcode(), 'name' => $product->getName(),
                'saleUnit' => $product->getSaleUnit()?->getName(), 'categoryIds' => array_values(array_map(fn ($c) => (int) $c->getId(), $product->getCategory()->toArray())),
                'price' => (int) round((float) $product->getPrice()), 'specialPrice' => $product->getSpecialPrice() !== null && $product->getSpecialPrice() > 0 ? (int) round((float) $product->getSpecialPrice()) : null,
                'specialPriceFrom' => $product->getSpecialPriceFrom()?->format(\DateTimeInterface::ATOM), 'specialPriceTo' => $product->getSpecialPriceTo()?->format(\DateTimeInterface::ATOM),
                'variants' => $variants,
            ];
        }
        $posSettings = $this->settings->settings();
        $content = [
            'site' => ['code' => (string) $this->tenants->getCurrentTenantCode(), 'currency' => strtoupper($this->currency->code()), 'taxProvider' => $this->offline->taxProvider(), 'cashRoundingStep' => $step,
                'country' => $address['country'] ?? null, 'province' => $address['province'] ?? null, 'name' => $e?->getName()],
            'cashRegisters' => array_map(fn (CashRegister $r) => ['id' => $r->getId(), 'name' => $r->getName(), 'receiptPrefix' => $r->getReceiptPrefix(), 'terminalEnabled' => $r->hasTerminal()],
                $em->getRepository(CashRegister::class)->findAllOrdered(true)),
            'taxes' => $taxes,
            'paymentMethods' => array_map(fn (PaymentMethod $m) => ['type' => (int) $m->getId(), 'label' => (string) $m->getName(), 'offline' => (int) $m->getId() === 2], $em->getRepository(PaymentMethod::class)->findBy([], ['id' => 'ASC'])),
            'denominations' => $this->denominations->list(),
            'products' => $products,
            'discounts' => $posSettings['discounts'] ?? [],
            'settings' => array_intersect_key($posSettings, array_flip(['pin', 'lockAfterMinutes', 'receipt', 'paymentMethods', 'cashFund', 'cashAlertThreshold', 'customItems', 'priceOverride', 'giftCards', 'customers', 'loyalty'])) + ['offline' => $settings],
            'calculationRule' => PosOfflineCalculator::RULE,
            'testCases' => '/api/pos/offline-cases',
        ];
        // Grille de favoris (F10) : références {productId, variantId, defaultVariantId, pinned, rank} (prix déjà dans products) ; clé ajoutée
        // seulement si les favoris sont activés : sinon contenu et ETag du pack inchangés
        if (($favorites = $this->favorites->packEntries($register, '')) !== null) {
            $content['favorites'] = $favorites;
        }
        $etag = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE));
        $now = new \DateTimeImmutable();
        $validUntil = $now->modify('+' . $settings['packValidityHours'] . ' hours');
        $snapshot = ['step' => $step, 'taxes' => $taxes, 'prices' => $prices];
        $repo = $em->getRepository(PosOfflinePack::class);
        /** @var ?PosOfflinePack $pack */
        $pack = $repo->findOneBy(['etag' => $etag], ['id' => 'DESC']);
        if ($pack === null || $pack->getValidUntil() < $now) {
            $pack = new PosOfflinePack($now->format('Ymd\THis') . '#' . substr($etag, 0, 8), $etag, $validUntil, $snapshot);
            $em->persist($pack);
            $em->flush();
        }

        return $content + ['packVersion' => $pack->getVersion(), 'generatedAt' => $pack->getGeneratedAt()->format(\DateTimeInterface::ATOM), 'serverTime' => $now->format(\DateTimeInterface::ATOM),
            'validUntil' => $pack->getValidUntil()->format(\DateTimeInterface::ATOM), 'etag' => $etag];
    }

    public function find(string $version): ?PosOfflinePack
    {
        return $this->emProvider->getEntityManager()->getRepository(PosOfflinePack::class)->findOneBy(['version' => $version]);
    }

    /** Jeu de cas partagé (config/pos/offline-cases.json) */
    public static function cases(string $projectDir): array
    {
        return json_decode((string) file_get_contents($projectDir . '/config/pos/offline-cases.json'), true) ?: ['rule' => PosOfflineCalculator::RULE, 'cases' => []];
    }

    private function storeAddress(): ?array
    {
        $raw = $this->taxAddress->forStore();

        return $raw ? TaxEngine::normalizeAddress($raw) : null;
    }
}
