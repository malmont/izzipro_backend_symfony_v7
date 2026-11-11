<?php

namespace App\Gestion\Services;

use App\Gestion\Entity\GestionCashMovement;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Frais fixes mensuels de la gestion (lot FF1, 11/11/2026, `docs/frais-fixes-gestion.md`, contrat validé par l'application). Saisis une fois
 * comme mouvements prévus de la trésorerie (B1b-1), sans deuxième table : un mouvement **sortant et récurrent** est un frais fixe si le gérant
 * l'a coché (`fixed_cost`), sinon selon sa catégorie (`GestionCashMovement::FIXED_COST_CATEGORIES` ; loan, tax, other : non). Un mouvement
 * ponctuel n'est jamais un frais fixe (une dépense ponctuelle va en note de frais). Équivalent mensuel : weekly × 52 ÷ 12, monthly × 1,
 * quarterly ÷ 3, yearly ÷ 12. Dans le résultat de période (P2), réparti au prorata des jours : équivalent mensuel × 12 ÷ 365 par jour couvert,
 * entre le début et la fin du mouvement, quelle que soit la date de paiement (la trésorerie, elle, suit les échéances).
 */
class GestionFixedCostService
{
    private const MONTHLY_FACTOR = ['weekly' => 52 / 12, 'monthly' => 1, 'quarterly' => 1 / 3, 'yearly' => 1 / 12];
    /** Mots qui font soupçonner un frais fixe saisi aussi en note de frais (remarque FIXED_COSTS_MAY_OVERLAP_NOTES) */
    private const OVERLAP_WORDS = ['loyer', 'bail', 'salaire', 'paie', 'assurance', 'abonnement', 'électricité', 'electricite', 'hydro', 'internet', 'téléphone', 'telephone', 'rent', 'salary', 'payroll', 'insurance', 'subscription', 'electricity', 'utilities'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TranslatorInterface $translator,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** Règle par défaut : sortant, récurrent, catégorie de frais fixes */
    public static function defaultFixed(string $category, string $direction, string $recurrence): bool
    {
        return $direction === 'out' && $recurrence !== 'none' && in_array($category, GestionCashMovement::FIXED_COST_CATEGORIES, true);
    }

    /** Frais fixe effectif d'une ligne de gestion_cash_movement : choix du gérant, sinon règle ; jamais pour une entrée ni un mouvement ponctuel */
    public static function isFixed(array $r): bool
    {
        if ($r['direction'] !== 'out' || $r['recurrence'] === 'none') {
            return false;
        }

        return $r['fixed_cost'] === null ? self::defaultFixed((string) $r['category'], (string) $r['direction'], (string) $r['recurrence']) : (bool) $r['fixed_cost'];
    }

    /** Équivalent mensuel en cents (0 pour un mouvement ponctuel) */
    public static function monthlyEquivalent(int $amount, string $recurrence): int
    {
        return (int) round($amount * (self::MONTHLY_FACTOR[$recurrence] ?? 0));
    }

    /**
     * GET /api/gestion/frais-fixes : frais fixes actifs aujourd'hui (début ≤ aujourd'hui, fin absente ou ≥ aujourd'hui), ou tous avec `$all`.
     *
     * @return array{currency: string, monthlyTotal: int, byCategory: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function list(bool $all, string $locale, \DateTimeImmutable $today): array
    {
        $day = $today->format('Y-m-d');
        $items = [];
        $byCategory = [];
        $total = 0;
        foreach ($this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_movement ORDER BY category, start_date, id') as $r) {
            $start = substr((string) $r['start_date'], 0, 10);
            $end = $r['end_date'] === null ? null : substr((string) $r['end_date'], 0, 10);
            if (!self::isFixed($r)) {
                continue;
            }
            $active = $start <= $day && ($end === null || $end >= $day);
            if (!$all && !$active) {
                continue;
            }
            $monthly = self::monthlyEquivalent((int) $r['amount_cents'], (string) $r['recurrence']);
            $items[] = [
                'id' => (int) $r['id'], 'label' => (string) $r['label'], 'category' => (string) $r['category'], 'categoryLabel' => $this->label((string) $r['category'], $locale),
                'amount' => (int) $r['amount_cents'], 'recurrence' => (string) $r['recurrence'], 'monthlyEquivalent' => $monthly,
                'startDate' => $start, 'endDate' => $end, 'active' => $active, 'fixedCostDefault' => $r['fixed_cost'] === null,
            ];
            if ($active) {
                $total += $monthly;
                $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + $monthly;
            }
        }

        return [
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'monthlyTotal' => $total,
            'byCategory' => $this->categories($byCategory, $locale, 'monthly'),
            'items' => $items,
        ];
    }

    /**
     * Frais fixes d'un intervalle de jours [from, to] (inclus, Y-m-d), au prorata des jours couverts par chaque mouvement (résultat P2).
     *
     * @return array{total: int, byCategory: list<array{category: string, label: string, amount: int}>, days: int, caveats: list<array{code: string, text: string}>}
     */
    public function between(string $from, string $to, string $locale = 'fr'): array
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a') + 1;
        $byCategory = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_movement') as $r) {
            if (!self::isFixed($r)) {
                continue;
            }
            $start = max($from, substr((string) $r['start_date'], 0, 10));
            $end = min($to, $r['end_date'] === null ? $to : substr((string) $r['end_date'], 0, 10));
            if ($start > $end) {
                continue;
            }
            $covered = (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->format('%a') + 1;
            $daily = self::monthlyEquivalent((int) $r['amount_cents'], (string) $r['recurrence']) * 12 / 365;
            $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + $daily * $covered;
        }
        $rounded = array_map(fn ($v) => (int) round($v), $byCategory);
        $caveats = [];
        if ($rounded !== []) {
            $caveats[] = ['code' => 'FIXED_COSTS_PRORATED', 'text' => $this->translator->trans('fixed_costs.caveats.FIXED_COSTS_PRORATED', [], 'gestion', $locale)];
            $names = $this->overlappingNotes($from, $to);
            if ($names !== []) {
                $caveats[] = ['code' => 'FIXED_COSTS_MAY_OVERLAP_NOTES', 'text' => $this->translator->trans('fixed_costs.caveats.FIXED_COSTS_MAY_OVERLAP_NOTES', ['%names%' => implode(', ', $names)], 'gestion', $locale), 'notes' => $names];
            }
        }

        return ['total' => array_sum($rounded), 'byCategory' => $this->categories($rounded, $locale, 'amount'), 'days' => $days, 'caveats' => $caveats];
    }

    /** @return list<string> noms (5 au plus) des notes de frais de [from, to] qui ressemblent à un frais fixe */
    private function overlappingNotes(string $from, string $to): array
    {
        $names = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT name, description FROM note_de_frais WHERE date >= ? AND date <= ? ORDER BY date, id', [$from, $to]) as $n) {
            $text = mb_strtolower(trim(($n['name'] ?? '') . ' ' . ($n['description'] ?? '')));
            foreach (self::OVERLAP_WORDS as $word) {
                if ($text !== '' && preg_match('/\b' . preg_quote($word, '/') . '/u', $text)) {
                    $names[] = (string) $n['name'];
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 5);
    }

    /** @return list<array<string, mixed>> par catégorie, dans l'ordre de GestionCashMovement::CATEGORIES */
    private function categories(array $amounts, string $locale, string $key): array
    {
        $out = [];
        foreach (GestionCashMovement::CATEGORIES as $c) {
            if (isset($amounts[$c])) {
                $out[] = ['category' => $c, 'label' => $this->label($c, $locale), $key => $amounts[$c]];
            }
        }

        return $out;
    }

    private function label(string $category, string $locale): string
    {
        return $this->translator->trans("cash.categories.$category", [], 'gestion', $locale);
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
