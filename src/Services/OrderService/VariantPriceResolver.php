<?php

namespace App\Services\OrderService;

use App\Entity\ProductVariant;
use App\Entity\VariantPromotion;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prix de vente d'une variante, un seul endroit (lot VAR, 11/11/2026, `docs/promotions-gestion.md` § 8) : devis de la boutique et de la caisse,
 * paiement, commande, favoris de la caisse, pack hors ligne v4 et fiche produit passent par ici.
 *
 * - Interrupteur du site éteint (`entreprise.variant_promotions_enabled`, défaut) : `variant.price ?? product.getEffectivePrice()`, strictement
 *   l'expression d'avant le lot ; les promotions de variante en base ne sont même pas lues.
 * - Allumé : une promotion de variante dont la fenêtre [starts_at, ends_at[ contient l'instant donne `price` ; la plus précise gagne (elle remplace
 *   la promotion de l'article, sans cumul). Une promotion dont le prix n'est plus sous le prix catalogue (prix propre, sinon prix normal de
 *   l'article, qui a pu baisser depuis) est ignorée : jamais un prix relevé par une promotion périmée.
 *
 * L'interrupteur est lu une fois par requête et par base ; `isOn()` / `display()` servent aux DTO statiques (fiche produit) : faux tant que le
 * service n'a pas été construit (le kernel.request ci-dessous le construit à chaque requête HTTP).
 */
final class VariantPriceResolver implements EventSubscriberInterface
{
    private static ?self $current = null;

    /** @var array<string, bool> interrupteur par base de données */
    private array $memo = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
        self::$current = $this;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['reset', 4096]];
    }

    public function reset(): void
    {
        $this->memo = [];
        self::$current = $this;
    }

    public function enabled(): bool
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $db = (string) $conn->getDatabase();
        if (!array_key_exists($db, $this->memo)) {
            $on = $conn->fetchOne('SELECT variant_promotions_enabled FROM entreprise ORDER BY id LIMIT 1');
            $this->memo[$db] = in_array($on, [true, 't', 1, '1'], true);
        }

        return $this->memo[$db];
    }

    public static function isOn(): bool
    {
        return self::$current !== null && self::$current->enabled();
    }

    /**
     * Prix unitaire de vente (cents) à l'instant donné, et la promotion de variante appliquée (forme de la ligne de devis) ou null.
     *
     * @return array{unitPrice: int, promotion: ?array{id: int, kind: string, percent: int|float, amount: int, catalogUnitPrice: int}}
     */
    public function sale(ProductVariant $variant, ?\DateTimeImmutable $at = null): array
    {
        $unit = (int) round($variant->getPrice() ?? $variant->getProduct()->getEffectivePrice());
        if (!$this->enabled()) {
            return ['unitPrice' => $unit, 'promotion' => null];
        }
        $promotion = self::activeAt($variant, $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($promotion === null) {
            return ['unitPrice' => $unit, 'promotion' => null];
        }
        $catalog = self::catalogPrice($variant);

        return ['unitPrice' => $promotion->getPrice(), 'promotion' => ['id' => (int) $promotion->getId(), 'kind' => 'variant', 'percent' => self::number($promotion->getPercent()),
            'amount' => $catalog - $promotion->getPrice(), 'catalogUnitPrice' => $catalog]];
    }

    /** Prix avant promotion (cents) : prix propre de la variante, sinon prix normal de l'article (jamais sa promotion) */
    public static function catalogPrice(ProductVariant $variant): int
    {
        return (int) round((float) ($variant->getPrice() ?? $variant->getProduct()->getPrice()));
    }

    /** Promotion de variante en vigueur à l'instant (sans regarder l'interrupteur) ; la plus récente si deux se recoupent */
    public static function activeAt(ProductVariant $variant, \DateTimeImmutable $at): ?VariantPromotion
    {
        $catalog = self::catalogPrice($variant);
        $found = null;
        foreach ($variant->getVariantPromotions() as $p) {
            [$from, $to] = $p->window();
            if ($from <= $at && $at < $to && $p->getPrice() < $catalog && ($found === null || $p->getId() > $found->getId())) {
                $found = $p;
            }
        }

        return $found;
    }

    /**
     * Fiche produit (`variants[].promotion`) : la promotion en cours, sinon la prochaine ; null si l'interrupteur est éteint ou s'il n'y en a pas.
     *
     * @return array{id: int, percent: int|float, price: int, basePrice: int, startDate: string, endDate: string, startsAt: string, endsAt: string, active: bool}|null
     */
    public static function display(ProductVariant $variant): ?array
    {
        if (!self::isOn()) {
            return null;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $catalog = self::catalogPrice($variant);
        $promotion = self::activeAt($variant, $now);
        if ($promotion === null) {
            foreach ($variant->getVariantPromotions() as $p) {
                [$from, $to] = $p->window();
                if ($from > $now && $to > $from && $p->getPrice() < $catalog) {
                    $promotion = $p;
                    break;
                }
            }
        }
        if ($promotion === null) {
            return null;
        }
        [$from, $to] = $promotion->window();

        return ['id' => (int) $promotion->getId(), 'percent' => self::number($promotion->getPercent()), 'price' => $promotion->getPrice(), 'basePrice' => $catalog,
            'startDate' => $promotion->getStartDate()->format('Y-m-d'), 'endDate' => $promotion->getEndDate()->format('Y-m-d'),
            'startsAt' => $from->format(\DateTimeInterface::ATOM), 'endsAt' => $to->format(\DateTimeInterface::ATOM), 'active' => $from <= $now && $now < $to];
    }

    /**
     * Pack hors ligne v4 : promotions de la variante encore à venir ou en cours (la tablette applique celle dont [from, to[ contient l'heure de
     * vente ; si deux se recoupent, l'identifiant le plus grand, c'est-à-dire la plus récemment créée). `percent` et `basePrice` (prix catalogue
     * actuel : prix propre, sinon prix normal de l'article) servent à l'affichage hors ligne (« −20 % », prix barré).
     *
     * @return list<array{id: int, price: int, from: string, to: string, percent: int|float, basePrice: int}>
     */
    public static function packEntries(ProductVariant $variant): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $catalog = self::catalogPrice($variant);
        $out = [];
        foreach ($variant->getVariantPromotions() as $p) {
            [$from, $to] = $p->window();
            if ($to > $now && $to > $from && $p->getPrice() < $catalog) {
                $out[] = ['id' => (int) $p->getId(), 'price' => $p->getPrice(), 'from' => $from->format(\DateTimeInterface::ATOM), 'to' => $to->format(\DateTimeInterface::ATOM),
                    'percent' => self::number($p->getPercent()), 'basePrice' => $catalog];
            }
        }

        return $out;
    }

    private static function number(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
