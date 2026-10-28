<?php

namespace App\Services\PosFavoriteService;

use App\Entity\CashRegister;
use App\Entity\Entreprise;
use App\Entity\PosFavorite;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Repository\PosFavoriteRepository;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\StoreClock;
use App\Services\MediaUrlResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;

/**
 * Grille de favoris de la caisse (F10) : produits épinglés par le responsable (site, ou poste qui REMPLACE le site, sans fusion), puis
 * plus vendus en caisse sur N jours (fuseau du magasin, nets des retours) jusqu'à la taille de la grille. Seulement les produits vendables
 * en caisse (isPos, vente activée, hors location) ; jamais les cartes-cadeaux ni les articles libres (sans variante). Prix : règle du devis
 * (prix de la variante, sinon prix effectif du produit, prix promotionnel compris).
 */
class PosFavoriteService
{
    public const MAX_PINS = 100;
    /** Classement des plus vendus gardé 10 minutes, et vidé par toute vente ou retour (étiquette des commandes, 28/10/2026) */
    private const BEST_SELLERS_TTL = 600;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StoreClock $clock,
        private readonly TenantCacheService $cache,
        private readonly PosAuditService $audit,
    ) {
    }

    public static function settingsOf(?Entreprise $e): array
    {
        return ['enabled' => $e?->isPosFavoritesEnabled() ?? false, 'mode' => $e?->getPosFavoritesMode() ?? 'both', 'days' => $e?->getPosFavoritesDays() ?? 30, 'gridSize' => $e?->getPosFavoritesGridSize() ?? 24];
    }

    public function settings(): array
    {
        return self::settingsOf($this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]));
    }

    /**
     * Grille de la caisse ; favoris désactivés : {enabled: false, items: []}.
     *
     * @return array{body: array, etag: string}
     */
    public function grid(?CashRegister $register, string $productsBaseUrl): array
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            $body = ['enabled' => false, 'items' => []];

            return ['body' => $body, 'etag' => hash('sha256', json_encode($body))];
        }
        [$scope, $pins] = $this->effectivePins($register);
        $items = [];
        $pinnedProducts = [];
        if ($settings['mode'] !== 'bestSellers') {
            foreach ($pins as $pin) {
                if (count($items) >= $settings['gridSize']) {
                    break;
                }
                if (self::sellable($pin->getProduct())) {
                    $items[] = $this->entry($pin->getProduct(), $pin->getProductVariant(), true, count($items) + 1, $productsBaseUrl);
                    $pinnedProducts[(int) $pin->getProduct()->getId()] = true;
                }
            }
        }
        if ($settings['mode'] !== 'pinned' && count($items) < $settings['gridSize']) {
            $em = $this->emProvider->getEntityManager();
            foreach ($this->bestSellers($settings['days']) as $row) {
                if (count($items) >= $settings['gridSize']) {
                    break;
                }
                if (isset($pinnedProducts[$row['productId']])) {
                    continue; // déjà épinglé (produit ou une de ses variantes)
                }
                $product = $em->find(Product::class, $row['productId']);
                if ($product !== null && self::sellable($product)) {
                    $items[] = $this->entry($product, null, false, count($items) + 1, $productsBaseUrl);
                }
            }
        }
        $body = ['enabled' => true, 'mode' => $settings['mode'], 'days' => $settings['days'], 'gridSize' => $settings['gridSize'],
            'scope' => $scope, 'cashRegisterId' => $register?->getId(), 'items' => $items];

        return ['body' => $body, 'etag' => hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE))];
    }

    /** Références des favoris pour le pack hors ligne (prix déjà dans le pack) ; null si désactivés (pack inchangé) */
    public function packEntries(?CashRegister $register, string $productsBaseUrl): ?array
    {
        // Épinglés seulement (relecture du 28/10/2026) : les plus vendus changent à chaque vente et créaient une version de pack (instantané
        // complet des prix) à chaque passage ; ils restent servis en ligne par GET /api/pos/favorites. Même format d'entrée, rangs 1..n.
        $settings = $this->settings();
        if (!$settings['enabled']) {
            return null;
        }
        $entries = [];
        if ($settings['mode'] !== 'bestSellers') {
            [, $pins] = $this->effectivePins($register);
            foreach ($pins as $pin) {
                if (count($entries) >= $settings['gridSize']) {
                    break;
                }
                if (self::sellable($pin->getProduct())) {
                    $e = $this->entry($pin->getProduct(), $pin->getProductVariant(), true, count($entries) + 1, $productsBaseUrl);
                    $entries[] = ['productId' => $e['productId'], 'variantId' => $e['variantId'], 'defaultVariantId' => $e['defaultVariantId'], 'pinned' => true, 'rank' => $e['rank']];
                }
            }
        }

        return $entries;
    }

    /** Épinglés d'une portée pour l'écran d'édition (responsable) */
    public function pins(User $user, ?CashRegister $register): array
    {
        self::requireManager($user);

        return $this->pinsBody($register);
    }

    /**
     * Remplace la liste ordonnée des épinglés d'une portée (épingler, retirer, réordonner en un appel) ; liste vide d'un poste : le
     * poste reprend les épinglés du site. {pins: [{productId, variantId?}]}. 422 VALIDATION_FAILED {errors: [{path, code, message}]}.
     */
    public function replacePins(User $user, ?CashRegister $register, mixed $pins): array
    {
        self::requireManager($user);
        if (!is_array($pins) || !array_is_list($pins)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'pins : liste [{productId, variantId?}] attendue', 422, [['path' => 'pins', 'code' => 'INVALID', 'message' => 'liste attendue']]);
        }
        if (count($pins) > self::MAX_PINS) {
            throw new CashRegisterException('VALIDATION_FAILED', sprintf('Au plus %d produits épinglés', self::MAX_PINS), 422, [['path' => 'pins', 'code' => 'TOO_MANY', 'message' => sprintf('au plus %d', self::MAX_PINS)]]);
        }
        $em = $this->emProvider->getEntityManager();
        $errors = [];
        $resolved = [];
        $seen = [];
        foreach ($pins as $i => $pin) {
            $path = "pins[$i]";
            $productId = is_array($pin) && is_numeric($pin['productId'] ?? null) ? (int) $pin['productId'] : null;
            $variantId = is_array($pin) && is_numeric($pin['variantId'] ?? null) ? (int) $pin['variantId'] : null;
            if ($productId === null || (is_array($pin) && isset($pin['variantId']) && $variantId === null)) {
                $errors[] = ['path' => $path, 'code' => 'INVALID', 'message' => '{productId, variantId?} entiers attendus'];
                continue;
            }
            $product = $em->find(Product::class, $productId);
            if ($product === null) {
                $errors[] = ['path' => "$path.productId", 'code' => 'PRODUCT_NOT_FOUND', 'message' => 'produit introuvable'];
                continue;
            }
            if (!self::sellable($product)) {
                $errors[] = ['path' => "$path.productId", 'code' => 'PRODUCT_NOT_SELLABLE', 'message' => 'produit non vendable en caisse (caisse, vente activée, hors location)'];
                continue;
            }
            $variant = null;
            if ($variantId !== null) {
                $variant = $em->find(ProductVariant::class, $variantId);
                if ($variant === null || $variant->getProduct()?->getId() !== $product->getId()) {
                    $errors[] = ['path' => "$path.variantId", 'code' => 'VARIANT_MISMATCH', 'message' => 'variante introuvable ou d\'un autre produit'];
                    continue;
                }
            }
            $key = $productId . '-' . ($variantId ?? 0);
            if (isset($seen[$key])) {
                $errors[] = ['path' => $path, 'code' => 'DUPLICATE', 'message' => 'déjà dans la liste'];
                continue;
            }
            $seen[$key] = true;
            $resolved[] = [$product, $variant];
        }
        if ($errors) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Épinglage refusé', 422, $errors);
        }

        $before = array_map(fn (PosFavorite $f) => self::ref($f->getProduct(), $f->getProductVariant()), $this->repo()->forScope($register));
        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($this->repo()->forScope($register) as $old) {
                $em->remove($old);
            }
            $em->flush();
            foreach ($resolved as $position => [$product, $variant]) {
                $em->persist((new PosFavorite())->setProduct($product)->setProductVariant($variant)->setCashRegister($register)->setPosition($position)->setCreatedById($user->getId()));
            }
            $em->flush();
            $this->audit->record(PosAuditService::FAVORITES_UPDATED, ['scope' => $register ? 'register' : 'site', 'cashRegisterId' => $register?->getId(),
                'before' => $before, 'after' => array_map(fn (array $r) => self::ref($r[0], $r[1]), $resolved)], null, $register, $register ? 'cash_register' : null, $register?->getId(), $user);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $this->pinsBody($register);
    }

    public static function sellable(?Product $product): bool
    {
        return $product !== null && $product->isPos() === true && $product->isSaleEnabled() && !$product->isRentalEnabled();
    }

    // ---------------------------------------------------------------- interne

    /** @return array{0: string, 1: list<PosFavorite>} portée effective : épinglés du poste s'il en a, sinon ceux du site */
    private function effectivePins(?CashRegister $register): array
    {
        if ($register !== null && ($own = $this->repo()->forScope($register)) !== []) {
            return ['register', $own];
        }

        return ['site', $this->repo()->forScope(null)];
    }

    private function pinsBody(?CashRegister $register): array
    {
        $own = $this->repo()->forScope($register);
        $source = $register !== null && $own === [] ? $this->repo()->forScope(null) : $own;

        return [
            'scope' => $register ? 'register' : 'site', 'cashRegisterId' => $register?->getId(),
            'inheritsSite' => $register !== null && $own === [], // poste sans épinglés propres : ceux du site s'appliquent (liste ci-dessous)
            'pins' => array_map(fn (PosFavorite $f) => ['id' => $f->getId(), 'position' => $f->getPosition(), 'productId' => (int) $f->getProduct()->getId(), 'variantId' => $f->getProductVariant()?->getId(),
                'name' => $f->getProduct()->getName(), 'variantLabel' => $f->getProductVariant() ? self::variantLabel($f->getProductVariant()) : null, 'sellable' => self::sellable($f->getProduct())], $source),
            'maxPins' => self::MAX_PINS,
        ];
    }

    /** @return list<array{productId: int, quantity: int}> */
    private function bestSellers(int $days): array
    {
        $today = (new \DateTimeImmutable('now', $this->clock->timezone()))->format('Y-m-d');
        $from = (new \DateTimeImmutable($today, $this->clock->timezone()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

        return $this->cache->get('pos_favorites_best_' . $days . '_' . $today, function () use ($from, $today) {
            [$start, $end] = $this->clock->range($from, $today);

            return $this->repo()->bestSellers($start, $end, 200);
        }, self::BEST_SELLERS_TTL, \App\Cache\CacheTags::listTags(\App\Cache\CacheTags::ORDERS));
    }

    private function entry(Product $product, ?ProductVariant $variant, bool $pinned, int $rank, string $productsBaseUrl): array
    {
        $variants = $product->getVariants();
        $single = $variant === null && count($variants) === 1 ? $variants->first() : null;
        $sold = $variant ?? ($single ?: null);

        return [
            'rank' => $rank, 'productId' => (int) $product->getId(), 'variantId' => $variant?->getId(),
            'defaultVariantId' => $sold?->getId(), // variante à vendre sans choix (épinglée ou unique) ; null : la caisse demande la variante
            'hasVariants' => $variant === null && count($variants) > 1,
            'name' => $product->getName(), 'variantLabel' => $variant ? self::variantLabel($variant) : null,
            'price' => (int) round((float) ($sold?->getPrice() ?? $product->getEffectivePrice())), // règle du devis (CartQuoteCalculator)
            'image' => MediaUrlResolver::joinStored($product->getImage(), $productsBaseUrl),
            'barcode' => ($sold?->getBarcode() ?: null) ?? ($product->getBarcode() ?: null),
            'pinned' => $pinned,
        ];
    }

    private static function variantLabel(ProductVariant $variant): ?string
    {
        return trim(implode(' / ', array_filter([$variant->getColor()?->getName(), $variant->getSize()?->getName()]))) ?: null;
    }

    private static function ref(?Product $product, ?ProductVariant $variant): array
    {
        return ['productId' => $product?->getId(), 'variantId' => $variant?->getId()];
    }

    private static function requireManager(User $user): void
    {
        if (!PosApprovalService::isManager($user)) {
            throw new CashRegisterException('FORBIDDEN', 'Épinglage des favoris réservé au responsable de caisse', 403);
        }
    }

    private function repo(): PosFavoriteRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(PosFavorite::class);
    }
}
