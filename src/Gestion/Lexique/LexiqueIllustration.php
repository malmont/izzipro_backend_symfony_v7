<?php

namespace App\Gestion\Lexique;

use App\Gestion\Services\GestionAdviceService;

/**
 * L'ILLUSTRATION d'une notion du lexique avec les chiffres du site (lot W2-APPRENDRE, A-1, 13/11/2026) : une phrase ÉCRITE PAR LE
 * SERVEUR, sans IA (« Votre taux de marque, du 1er au 13 novembre 2026 : 44 %. Sur 100,00 $ vendus, il vous reste 44,00 $ après le
 * coût d'achat. »). Chaque nombre vient d'un chiffre de l'analyse liée à la notion, ou d'un calcul fait ici à partir d'eux (la part
 * gardée sur 100) : aucun nombre inventé. Sans donnée utile (aucune vente, aucun budget, aucun solde) : pas d'illustration, la
 * définition suffit.
 */
class LexiqueIllustration
{
    public function __construct(private readonly LexiqueTexts $texts, private readonly GestionAdviceService $format)
    {
    }

    /**
     * @param list<array<string, mixed>> $figures chiffres de l'analyse liée (`id` sans préfixe, `value`, `unit`)
     * @param array{label?: string}|null $period plage de l'analyse liée
     */
    public function of(string $key, array $figures, ?array $period, string $locale): ?string
    {
        $byId = [];
        foreach ($figures as $f) {
            $id = (string) $f['id'];
            $byId[str_contains($id, '.') ? substr($id, (int) strrpos($id, '.') + 1) : $id] = $f['value'];
        }
        $n = fn (string $id): int|float|null => isset($byId[$id]) && is_numeric($byId[$id]) ? $byId[$id] + 0 : null;
        $money = fn (int|float|null $cents): string => $this->format->format((int) round((float) $cents), 'money', $locale);
        $day = fn (string $id): ?string => isset($byId[$id]) && is_string($byId[$id]) && $byId[$id] !== '' ? $this->format->format($byId[$id], 'date', $locale) : null;
        $known = fn (string ...$ids): bool => array_filter(array_map($n, $ids), fn ($v) => $v === null) === [];
        $id = 'illustration';
        $params = match ($key) {
            'taux-de-marque' => $known('grossMarginRate', 'revenue') && $n('revenue') > 0
                ? ['%rate%' => $this->format->format($n('grossMarginRate'), 'percent', $locale), '%hundred%' => $money(10000), '%kept%' => $money($n('grossMarginRate') * 100)] : null,
            'marge' => $known('grossMargin', 'revenue') && $n('revenue') > 0 ? ['%revenue%' => $money($n('revenue')), '%margin%' => $money($n('grossMargin'))] : null,
            'ventes' => $known('revenue') && $n('revenue') > 0 ? ['%revenue%' => $money($n('revenue'))] : null,
            'panier-moyen' => $known('averageBasket', 'revenue', 'orders') && $n('orders') > 0
                ? ['%count%' => (int) $n('orders'), '%revenue%' => $money($n('revenue')), '%basket%' => $money($n('averageBasket'))] : null,
            'resultat' => $known('netResult', 'grossMargin', 'expenses') && ($n('grossMargin') != 0 || $n('expenses') != 0)
                ? ['%margin%' => $money($n('grossMargin')), '%expenses%' => $money($n('expenses')), '%result%' => $money($n('netResult'))] : null,
            'frais' => $known('expenses') && $n('expenses') > 0 ? ['%expenses%' => $money($n('expenses'))] : null,
            'budget', 'reste' => $known('planned', 'actual', 'remaining') && $n('planned') > 0
                ? ['%planned%' => $money($n('planned')), '%actual%' => $money($n('actual')), '%remaining%' => $money($n('remaining'))] : null,
            'point-mort' => $known('breakEvenSales', 'sales') ? ['%breakEven%' => $money($n('breakEvenSales')), '%sales%' => $money($n('sales'))] : null,
            'solde' => $known('balance') && $day('balanceDate') !== null ? ['%balance%' => $money($n('balance')), '%date%' => $day('balanceDate')] : null,
            'tresorerie' => $known('balance', 'lowestAmount') ? ['%balance%' => $money($n('balance')), '%low%' => $money($n('lowestAmount'))] : null,
            'point-bas' => $known('lowestAmount') && $day('lowestDate') !== null ? ['%low%' => $money($n('lowestAmount')), '%date%' => $day('lowestDate')] : null,
            'cout-d-achat' => $known('missingCostItems', 'items') && $n('items') > 0 ? ['%count%' => (int) $n('missingCostItems'), '%total%' => (string) (int) $n('items')] : null,
            'stock-bas' => $known('lowStockItems') ? ['%count%' => (int) $n('lowStockItems')] : null,
            'valeur-stock' => $known('stockSaleValue', 'stockCostValue') && $n('stockSaleValue') > 0 ? ['%sale%' => $money($n('stockSaleValue')), '%cost%' => $money($n('stockCostValue'))] : null,
            default => null,
        };
        if ($params === null) {
            return null;
        }
        if (in_array($key, ['cout-d-achat', 'stock-bas'], true) && $params['%count%'] === 0) {
            return $this->texts->text("$key.illustration_none", $locale);
        }

        return $this->texts->text("$key.$id", $locale, $params + ['%period%' => (string) ($period['label'] ?? '')]);
    }
}
