<?php

namespace App\Services\GestionService;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Avis du guide sur une période préparée (lot P6, 11/11/2026, `docs/preparation-periode.md` § 4) : candidats calculés par le backend et repassés
 * dans la simulation (mon plan, ventes de l'an dernier, achats réduits pour la trésorerie, achats ajustés au besoin + 20 %), recommandation
 * rédigée par l'IA (mêmes vérification, cache et plafond partagé de 10 par jour que G1b-2, `GestionSimulationCommentService`).
 */
class GestionPreparationAdviceService
{
    public function __construct(
        private readonly GestionPreparationService $preparation,
        private readonly GestionSimulationCommentService $comments,
        private readonly GestionAdviceService $advices,
        private readonly PeriodCalendar $calendar,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return array<string, mixed> { simulation, candidates, comment, ai, cached } */
    public function advise(array $body, string $locale, ?string $user, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $sim = $this->preparation->simulate($body, $today);
        $period = $this->preparation->period($sim['period']['id'], null);
        $clean = array_diff_key($body, ['previewHash' => 1]);
        $hash = hash('sha256', 'prep:' . json_encode([$clean, $locale, $sim['previewHash']]));
        $day = $today->format('Y-m-d');
        if (($cached = $this->comments->cached($period, $hash, $day)) !== null) {
            return $cached + ['cached' => true];
        }
        $candidates = $this->candidates($body, $sim, $today, $locale);
        $fallback = $this->fallback($sim, $candidates, $locale);
        if (!$this->comments->underLimit($day)) {
            throw new GestionConflictException('COMMENT_LIMIT', sprintf('%d avis du guide par jour au plus : voici l\'avis calculé sans l\'IA.', GestionSimulationCommentService::PER_DAY), 429,
                ['simulation' => $sim, 'candidates' => $candidates, 'comment' => $fallback, 'ai' => ['status' => 'rules_only']]);
        }
        $f = fn ($v, string $u = 'money') => $v === null ? null : $this->advices->format($v, $u, $locale);
        $numbers = [];
        $add = function (mixed $v, string $unit) use (&$numbers): void {
            if ($v !== null) {
                $numbers[] = ['value' => $v, 'unit' => $unit];
            }
        };
        foreach ($candidates as $c) {
            foreach ([$c['purchases'], $c['sales'], $c['grossMargin'], $c['result'], $c['cashLow']['amount'] ?? null, $c['stockEndSaleValue'], $c['delta']['result'], $c['delta']['cashLow'], $c['delta']['purchases']] as $v) {
                $add($v, 'money');
            }
            $add($c['cashLow']['date'] ?? null, 'date');
        }
        $plan = $sim['plan'];
        foreach ([$plan['sales']['target'], $plan['expenses']['total'], $plan['stockStart']['saleValue'], $plan['purchases']['saleValue'], $sim['lastYear']['revenue'] ?? null, $sim['lastYear']['result'] ?? null, $plan['cash']['threshold'] ?? null] as $v) {
            $add($v, 'money');
        }
        $add($plan['tauxMarque'], 'percent');
        $add($plan['purchases']['coefficient'], 'ratio');
        $payload = [
            'period' => $sim['period'], 'salesMethod' => $plan['sales']['method'], 'salesTarget' => $f($plan['sales']['target']),
            'lastYear' => $sim['lastYear'] === null ? null : ['sales' => $f($sim['lastYear']['revenue']), 'result' => $f($sim['lastYear']['result'])],
            'stockAvailableAtSalePrice' => $f($plan['stockStart']['saleValue'] + $plan['purchases']['saleValue']), 'cashThreshold' => $f($plan['cash']['threshold'] ?? null),
            'tripInOtherPeriod' => $plan['expenses']['tripInOtherPeriod'],
            'candidates' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'purchases' => $f($c['purchases']), 'sales' => $f($c['sales']), 'grossMargin' => $f($c['grossMargin']),
                'result' => $f($c['result']), 'cashLow' => $c['cashLow'] === null ? null : ['date' => $f($c['cashLow']['date'], 'date'), 'amount' => $f($c['cashLow']['amount'])],
                'stockLeftAtSalePrice' => $f($c['stockEndSaleValue']), 'resultVsMyPlan' => $f($c['delta']['result']), 'purchasesVsMyPlan' => $f($c['delta']['purchases'])], $candidates),
            'fallback' => $fallback,
        ];
        [$comment, $status, $usageId] = $this->comments->askGuide($period, $payload, $numbers, array_column($candidates, 'key'), $fallback, $locale, $user, $this->system($locale));
        $response = ['simulation' => $sim, 'candidates' => $candidates, 'comment' => $comment, 'ai' => ['status' => $status]];
        $this->comments->store($period, $hash, $day, $response, $status, $usageId, $user);

        return $response + ['cached' => false];
    }

    /**
     * myPlan ; lastYearSales (objectif de l'an dernier) ; cashSafe (achats datés jusqu'au point bas réduits, les derniers d'abord, juste assez pour
     * remonter au seuil) ; enoughStock (achats mis à l'échelle pour que le disponible couvre l'objectif + 20 %).
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(array $body, array $sim, \DateTimeImmutable $today, string $locale): array
    {
        $plans = ['myPlan' => $sim];
        $method = $sim['plan']['sales']['method'];
        if ($sim['estimations']['lastYear'] !== null && $method !== 'lastYear') {
            $plans['lastYearSales'] = $this->preparation->simulate(['sales' => ['method' => 'lastYear']] + $body, $today);
        }
        $purchases = is_array($body['purchases'] ?? null) ? array_values($body['purchases']) : [];
        $cash = $sim['plan']['cash'];
        if ($cash !== null && $purchases !== [] && $cash['low'] !== null && $cash['low']['amount'] < $cash['threshold']) {
            $need = $cash['threshold'] - $cash['low']['amount'];
            $order = array_keys($purchases);
            usort($order, fn ($a, $b) => strcmp((string) $purchases[$b]['date'], (string) $purchases[$a]['date']));
            $cut = $purchases;
            foreach ($order as $i) {
                if ($need <= 0) {
                    break;
                }
                if ((string) $purchases[$i]['date'] > $cash['low']['date']) {
                    continue;
                }
                $take = min($need, (int) $purchases[$i]['amount']);
                $cut[$i]['amount'] = (int) $purchases[$i]['amount'] - $take;
                $need -= $take;
            }
            $cut = array_values(array_filter($cut, fn ($p) => (int) $p['amount'] > 0));
            if ($cut != $purchases) {
                $plans['cashSafe'] = $this->preparation->simulate(['purchases' => $cut] + $body, $today);
            }
        }
        if ($purchases !== [] && $sim['plan']['purchases']['saleValue'] > 0) {
            $needed = max(0.0, (1 + GestionPreparationService::ENOUGH_STOCK_MARGIN) * $sim['plan']['sales']['target'] - $sim['plan']['stockStart']['saleValue']);
            $factor = $needed / $sim['plan']['purchases']['saleValue'];
            if (abs($factor - 1) > 0.02) {
                $scaled = array_values(array_filter(array_map(fn ($p) => ['amount' => (int) round((int) $p['amount'] * $factor)] + $p, $purchases), fn ($p) => $p['amount'] > 0));
                $plans['enoughStock'] = $this->preparation->simulate(['purchases' => $scaled] + $body, $today);
            }
        }
        $my = $sim['plan'];
        $out = [];
        foreach ($plans as $key => $s) {
            $p = $s['plan'];
            $out[] = [
                'key' => $key, 'label' => $this->translator->trans("preparation.candidates.$key", [], 'gestion', $locale),
                'purchases' => $p['purchases']['total'], 'sales' => $p['sales']['planned'], 'grossMargin' => $p['grossMargin'], 'result' => $p['result'],
                'cashLow' => $p['cash']['low'] ?? null, 'stockEndSaleValue' => $p['stockEnd']['saleValue'], 'planned' => $s['planned'],
                'delta' => ['result' => $p['result'] - $my['result'], 'purchases' => $p['purchases']['total'] - $my['purchases']['total'],
                    'cashLow' => isset($p['cash']['low'], $my['cash']['low']) ? $p['cash']['low']['amount'] - $my['cash']['low']['amount'] : null],
            ];
        }

        return $out;
    }

    private function system(string $locale): string
    {
        $language = $locale === 'en' ? 'anglais' : 'français';

        return "Tu es le guide de gestion d'un commerçant indépendant. On te donne le plan de sa prochaine période (objectif de ventes, achats de stock, voyage d'achat, frais) et plusieurs variantes, toutes calculées par le logiciel.\n"
            . "Ta tâche : recommander une variante (`recommended`, une des clés fournies), avec un titre court (60 caractères au plus) et une explication de deux ou trois phrases (400 caractères), en $language, au vouvoiement.\n"
            . "Règles strictes :\n"
            . "- N'écris AUCUN nombre qui ne figure pas tel quel dans les données. Recopie les valeurs telles qu'elles sont écrites ; n'additionne, ne soustrais, ne multiplie rien.\n"
            . "- Les achats de stock ne sont pas une charge, mais ils font le stock à vendre : en acheter moins peut plafonner les ventes et donc baisser le résultat (comparer `resultVsMyPlan`) ; en acheter trop laisse du stock en fin de période et pèse sur la trésorerie.\n"
            . "- Si la trésorerie passe sous le seuil (`cashLow` sous `cashThreshold`), la priorité est de la protéger.\n"
            . "- Rien ne s'applique tout seul : le commerçant décide.";
    }

    /** Avis des règles : cashSafe si la trésorerie passe sous le seuil ; enoughStock si l'objectif dépasse le stock ou si l'on achète bien plus que le besoin ; sinon mon plan */
    private function fallback(array $sim, array $candidates, string $locale): array
    {
        $by = array_column($candidates, null, 'key');
        $money = fn (?int $v) => $v === null ? '—' : $this->advices->format($v, 'money', $locale);
        $cash = $sim['plan']['cash'];
        if (isset($by['cashSafe'])) {
            return ['recommended' => 'cashSafe', 'textSource' => 'rules', 'title' => $this->translator->trans('preparation.comment.cash_title', [], 'gestion', $locale),
                'text' => $this->translator->trans('preparation.comment.cash_text', ['%low%' => $money($cash['low']['amount']), '%date%' => $this->advices->format($cash['low']['date'], 'date', $locale),
                    '%threshold%' => $money($cash['threshold']), '%cut%' => $money(-$by['cashSafe']['delta']['purchases'])], 'gestion', $locale)];
        }
        if (isset($by['enoughStock']) && ($sim['plan']['sales']['capped'] || $by['enoughStock']['delta']['purchases'] < 0)) {
            return ['recommended' => 'enoughStock', 'textSource' => 'rules', 'title' => $this->translator->trans('preparation.comment.stock_title', [], 'gestion', $locale),
                'text' => $this->translator->trans($sim['plan']['sales']['capped'] ? 'preparation.comment.stock_short' : ($by['enoughStock']['purchases'] === 0 ? 'preparation.comment.stock_none' : 'preparation.comment.stock_over'), ['%purchases%' => $money($by['enoughStock']['purchases'])], 'gestion', $locale)];
        }

        return ['recommended' => 'myPlan', 'textSource' => 'rules', 'title' => $this->translator->trans('preparation.comment.title', [], 'gestion', $locale),
            'text' => $this->translator->trans('preparation.comment.plan_text', ['%result%' => $money($sim['plan']['result']), '%sales%' => $money($sim['plan']['sales']['planned'])], 'gestion', $locale)];
    }
}
