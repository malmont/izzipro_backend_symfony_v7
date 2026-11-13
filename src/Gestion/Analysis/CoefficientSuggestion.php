<?php

namespace App\Gestion\Analysis;

use App\Gestion\Pricing\CoefficientRule;
use App\Gestion\Services\GestionPriceSettingsService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Coefficient proposé pour un article acheté (lot IA-ACT-5, 13/11/2026, `docs/prix-gestion.md` § 6), sans IA. Dans l'ordre :
 * 1. d'après ses SEMBLABLES d'une saison terminée (`SimilarArticlesAnalysis`, règle `CoefficientRule`), s'il y en a au moins 5 ;
 * 2. sinon le coefficient CONSTATÉ chez le fournisseur, s'il a au moins 5 articles ;
 * 3. sinon le coefficient par défaut du SITE.
 * Le gérant voit toutes les options qui existent, chacune avec les chiffres qui la justifient dans son libellé ; la première est proposée.
 * Aucune option n'est sous le coefficient minimum du site.
 */
class CoefficientSuggestion implements \Symfony\Contracts\Service\ResetInterface
{
    public const SOURCE_SIMILAR = 'similar';
    public const SOURCE_SUPPLIER = 'supplier';
    public const SOURCE_SITE = 'site';
    public const MIN_SUPPLIER_ARTICLES = 5;

    public function __construct(private readonly SimilarArticlesAnalysis $analysis, private readonly GestionPriceSettingsService $settings, private readonly TranslatorInterface $translator,
        private readonly \App\Services\TenantEntityManagerProvider $emProvider)
    {
    }

    /** @var array<string, array<string, mixed>> propositions déjà calculées dans cette requête (le fil relit ses questions plusieurs fois) */
    private array $memo = [];

    /**
     * @param ?int $supplierId null : fournisseur pas encore connu, ou créé par la facture (aucun historique) → le réglage du site
     * @param ?int $costCents coût unitaire dans la devise du site ; null : coût inconnu → pas de semblables
     * @param list<int> $categoryIds catégories de l'article (vide pour un article créé par une facture)
     *
     * @return array{proposed: float, source: string, options: list<array{value: float, source: string, label: string, figures: array<string, mixed>}>, similar: ?array<string, mixed>}
     */
    public function suggest(?int $supplierId, ?int $costCents, array $categoryIds, \DateTimeImmutable $day, string $locale, ?int $excludeProductId = null): array
    {
        $key = implode('|', [$supplierId ?? '-', $costCents ?? '-', implode(',', $categoryIds), $day->format('Y-m-d'), $locale, $excludeProductId ?? '-']);

        return $this->memo[$key] ??= $this->compute($supplierId, $costCents, $categoryIds, $day, $locale, $excludeProductId);
    }

    /** Oublie les propositions calculées (entre deux requêtes d'un processus qui dure, et dans les tests) */
    public function reset(): void
    {
        $this->memo = [];
    }

    /** @param list<int> $categoryIds */
    private function compute(?int $supplierId, ?int $costCents, array $categoryIds, \DateTimeImmutable $day, string $locale, ?int $excludeProductId): array
    {
        $min = $this->settings->minCoefficient();
        $similar = $supplierId === null || $costCents === null || $costCents <= 0 ? null : $this->analysis->similar($supplierId, $costCents, $categoryIds, $day, $excludeProductId, $locale);
        $options = [];
        $rule = $similar === null || $similar['displayedCoefficient'] === null ? null : CoefficientRule::propose($similar['count'], $similar['displayedCoefficient'],
            $similar['obtainedCoefficient'] ?? $similar['displayedCoefficient'], (float) ($similar['soldShare'] ?? 0.0), $similar['discountRate'], $min);
        if ($rule !== null) {
            $options[] = ['value' => $rule['coefficient'], 'source' => self::SOURCE_SIMILAR, 'label' => $this->similarLabel($rule, $similar, $min, $locale),
                'figures' => ['count' => $similar['count'], 'sameCategory' => $similar['criteria']['sameCategory'], 'season' => $similar['season'], 'displayedCoefficient' => $similar['displayedCoefficient'],
                    'obtainedCoefficient' => $similar['obtainedCoefficient'], 'soldShare' => $similar['soldShare'], 'weeksToSell' => $similar['weeksToSell'], 'discountRate' => $similar['discountRate'],
                    'adjustment' => $rule['adjustment'], 'floored' => $rule['floored']]];
        }
        $supplier = $supplierId === null ? null : $this->analysis->supplierCoefficient($supplierId, $excludeProductId);
        if ($supplier !== null && $supplier['articles'] >= self::MIN_SUPPLIER_ARTICLES) {
            $value = max($min, $supplier['coefficient']);
            $options[] = ['value' => $value, 'source' => self::SOURCE_SUPPLIER, 'label' => $this->tr('prices.coefficient.supplier', $locale, ['%coefficient%' => self::number($value, $locale), '%count%' => (string) $supplier['articles']])
                . ($value > $supplier['coefficient'] ? ' ' . $this->tr('prices.coefficient.floored', $locale, ['%min%' => self::number($min, $locale)]) : ''),
                'figures' => ['articles' => $supplier['articles'], 'observedCoefficient' => $supplier['coefficient'], 'floored' => $value > $supplier['coefficient']]];
        }
        $site = max($min, $this->settings->defaultCoefficient());
        $options[] = ['value' => $site, 'source' => self::SOURCE_SITE, 'label' => $this->tr('prices.coefficient.site', $locale, ['%coefficient%' => self::number($site, $locale)]), 'figures' => ['minCoefficient' => $min]];

        return ['proposed' => $options[0]['value'], 'source' => $options[0]['source'], 'options' => $options, 'similar' => $similar];
    }

    /**
     * Bilan d'un article DÉJÀ EN VENTE face à ses semblables (brique du bilan des nouveaux articles de la console, lot W2) : son coût
     * courant (moyenne pondérée de ses lignes d'achat, sinon le prix d'achat de la fiche), son coefficient actuel, le coefficient que le
     * serveur proposerait, et le VERDICT calculé par le serveur (`CoefficientRule::verdict` : garder, monter, baisser), jamais par l'IA.
     * Le fournisseur est celui de son dernier achat. Lecture seule ; null si l'article n'existe pas.
     *
     * @return array{product: array{id: int, name: ?string}, supplierId: ?int, cost: ?int, price: ?int, coefficient: ?float,
     *               proposal: array{value: float, source: string, label: string}, verdict: ?string, similar: ?array<string, mixed>}|null
     */
    public function review(int $productId, \DateTimeImmutable $day, string $locale): ?array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT p.id, p.name, p.price, COALESCE((SELECT SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(c.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0)
                 FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL), p.purchase_price) AS cost,
                 (SELECT c.fournisseur_id FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE l.product_id = p.id AND c.fournisseur_id IS NOT NULL ORDER BY c.date DESC, c.id DESC LIMIT 1) AS supplier
             FROM product p WHERE p.id = ?', [$productId]);
        if ($row === false) {
            return null;
        }
        $cost = $row['cost'] === null ? null : (int) round((float) $row['cost']);
        $price = $row['price'] === null ? null : (int) round((float) $row['price']);
        $categories = array_map('intval', $conn->fetchFirstColumn('SELECT categories_id FROM product_categories WHERE product_id = ?', [$productId]));
        $suggestion = $this->suggest($row['supplier'] === null ? null : (int) $row['supplier'], $cost, $categories, $day, $locale, $productId);
        $coefficient = $cost !== null && $cost > 0 && $price !== null && $price > 0 ? round($price / $cost, 2) : null;

        return ['product' => ['id' => (int) $row['id'], 'name' => $row['name']], 'supplierId' => $row['supplier'] === null ? null : (int) $row['supplier'], 'cost' => $cost, 'price' => $price, 'coefficient' => $coefficient,
            'proposal' => ['value' => $suggestion['proposed'], 'source' => $suggestion['source'], 'label' => $suggestion['options'][0]['label']],
            'verdict' => $coefficient === null ? null : CoefficientRule::verdict($coefficient, $suggestion['proposed']), 'similar' => $suggestion['similar']];
    }

    /** « 2,7 : 8 semblables affichés à 2,5, vendus à 85 % en 3 semaines (unités vendues), sans rabais (+0,2) » */
    private function similarLabel(array $rule, array $similar, float $min, string $locale): string
    {
        $percent = fn (float $share): string => (string) (int) round($share * 100);
        $adjustment = match ($rule['adjustment']) {
            CoefficientRule::ADJUST_UP => $this->tr('prices.coefficient.adjust.up', $locale, ['%step%' => self::number(CoefficientRule::STEP, $locale)]),
            CoefficientRule::ADJUST_DOWN => $this->tr('prices.coefficient.adjust.down', $locale, ['%step%' => self::number(CoefficientRule::STEP, $locale)]),
            CoefficientRule::ADJUST_OBTAINED => $this->tr('prices.coefficient.adjust.obtained', $locale),
            default => $this->tr('prices.coefficient.adjust.keep', $locale),
        };

        return $this->tr('prices.coefficient.similar', $locale, [
            '%coefficient%' => self::number($rule['coefficient'], $locale), '%count%' => (string) $similar['count'],
            '%scope%' => $similar['criteria']['sameCategory'] ? '' : ' ' . $this->tr('prices.coefficient.scope_supplier', $locale),
            '%displayed%' => self::number((float) $similar['displayedCoefficient'], $locale), '%sold%' => $percent((float) ($similar['soldShare'] ?? 0.0)),
            '%weeks%' => $similar['weeksToSell'] === null ? '' : ' ' . $this->tr('prices.coefficient.weeks', $locale, ['%count%' => (string) (int) max(1, round($similar['weeksToSell']))]),
            '%discount%' => $rule['discounted'] ? $this->tr('prices.coefficient.discount.with', $locale, ['%rate%' => $percent($similar['discountRate'])]) : $this->tr('prices.coefficient.discount.without', $locale),
            '%adjustment%' => $adjustment,
        ]) . ($rule['floored'] ? ' ' . $this->tr('prices.coefficient.floored', $locale, ['%min%' => self::number($min, $locale)]) : '');
    }

    /** Coefficient en clair : « 2,7 », « 2,45 », « 2 » (virgule en français, point en anglais, sans zéros inutiles) */
    public static function number(float $value, string $locale): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $locale === 'en' ? $text : str_replace('.', ',', $text);
    }

    /** @param array<string, string> $params */
    private function tr(string $id, string $locale, array $params = []): string
    {
        return $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
