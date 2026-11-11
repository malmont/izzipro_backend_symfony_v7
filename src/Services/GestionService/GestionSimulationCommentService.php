<?php

namespace App\Services\GestionService;

use App\Entity\AiUsage;
use App\Entity\Collections;
use App\Entity\GestionBudgetLine;
use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Commentaire du simulateur par le guide (lot G1b-2, 11/11/2026, `docs/guide-ia-gestion.md` § 9) : le backend calcule des répartitions du prévu
 * (garder, celle du gérant, parts de l'an dernier, dépenses de l'an dernier, achats réduits pour garder la trésorerie au-dessus du seuil), chacune
 * repassée dans le simulateur avec les leviers du gérant ; l'IA en recommande une et explique, sans aucun nombre hors de ces calculs (sinon
 * texte de repli). Aucune écriture hors du cache des commentaires ; appliquer = PUT du budget avec le `planned` choisi.
 */
class GestionSimulationCommentService
{
    public const MODEL = 'claude-sonnet-5-5';
    public const COST = 1;
    public const TIMEOUT = 30.0;
    public const PER_DAY = 10;
    public const KEYS = ['keep', 'simulated', 'lastYearShares', 'lastYearActual', 'cashSafe'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly GestionSimulationService $simulation,
        private readonly GestionBudgetService $budgets,
        private readonly GestionCashService $cash,
        private readonly GestionAdviceService $advices,
        private readonly PeriodCalendar $calendar,
        private readonly LandingAiClientInterface $client,
        private readonly LandingAiQuotaService $quota,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Platform\EntitlementService $entitlements = null,
        #[Autowire('%kernel.debug%')] private readonly bool $debug = false,
    ) {
    }

    /** @return array<string, mixed> { simulation, candidates, comment, ai, cached } */
    public function comment(Collections $period, array $body, string $locale, ?string $user, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $simulation = $this->simulation->simulate($period, $body, $locale, $today); // valide le corps, 404 / 409 comme la simulation
        $levers = $simulation['levers'];
        $hash = hash('sha256', (string) json_encode([$levers['pricePercent'], $levers['promotion'], $levers['advertisingDelta'], (array) $levers['planned'], $locale]));
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $day = $today->format('Y-m-d');
        if (($cached = $this->cached($period, $hash, $day)) !== null) {
            return $cached + ['cached' => true];
        }
        $candidates = $this->candidates($period, $body, $simulation, $locale, $today);
        $fallback = $this->fallback($candidates, $locale);
        if ((int) $conn->fetchOne('SELECT COUNT(*) FROM gestion_simulation_comment WHERE store_day = ?', [$day]) >= self::PER_DAY) {
            throw new GestionConflictException('COMMENT_LIMIT', sprintf('%d avis du guide par jour au plus : voici l\'avis calculé sans l\'IA.', self::PER_DAY), 429,
                ['simulation' => $simulation, 'candidates' => $candidates, 'comment' => $fallback, 'ai' => ['status' => 'rules_only']]);
        }
        [$comment, $status, $usageId] = $this->ai($period, $simulation, $candidates, $fallback, $locale, $user);
        $response = ['simulation' => $simulation, 'candidates' => $candidates, 'comment' => $comment, 'ai' => ['status' => $status]];
        $conn->executeStatement(
            'INSERT INTO gestion_simulation_comment (period_id, body_hash, store_day, response, ai_status, ai_usage_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON CONFLICT DO NOTHING',
            [$period->getId(), $hash, $day, json_encode($response, JSON_UNESCAPED_UNICODE), $status, $usageId, $user]
        );

        return $response + ['cached' => false];
    }

    /** Cache et plafond partagés (P6) : réponse gardée pour cette empreinte aujourd'hui, ou null ; 429 COMMENT_LIMIT au-delà de 10 par jour */
    public function cached(Collections $period, string $hash, string $day): ?array
    {
        $cached = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT response FROM gestion_simulation_comment WHERE period_id = ? AND body_hash = ? AND store_day = ?', [$period->getId(), $hash, $day]);

        if ($cached === false) {
            return null;
        }
        $response = json_decode((string) $cached, true) ?: [];
        if (($response['comment']['textSource'] ?? null) === 'ai') { // avis stocké avant le nettoyage (11/11/2026) : nettoyé, sinon recalculé
            $title = GestionAdviceService::cleanText($response['comment']['title'] ?? null);
            $text = GestionAdviceService::cleanText($response['comment']['text'] ?? null);
            if ($title === null || $text === null) {
                return null;
            }
            $response['comment']['title'] = $title;
            $response['comment']['text'] = $text;
        }

        return $response;
    }

    public function underLimit(string $day): bool
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM gestion_simulation_comment WHERE store_day = ?', [$day]) < self::PER_DAY;
    }

    public function store(Collections $period, string $hash, string $day, array $response, string $status, ?int $usageId, ?string $user): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO gestion_simulation_comment (period_id, body_hash, store_day, response, ai_status, ai_usage_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON CONFLICT DO NOTHING',
            [$period->getId(), $hash, $day, json_encode($response, JSON_UNESCAPED_UNICODE), $status, $usageId, $user]
        );
    }

    /**
     * Répartitions du prévu, chacune simulée avec les leviers du gérant (seul le prévu change) ; `delta` par rapport à « keep ».
     * Un changement des seuls achats ne modifie pas `result` (le stock n'est pas une charge) : seule la trésorerie après achats change.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(Collections $period, array $body, array $simulation, string $locale, \DateTimeImmutable $today): array
    {
        $budget = $this->budgets->get($period, $locale);
        $saved = array_column($budget['lines'], 'planned', 'category');
        $actual = array_column($budget['lines'], 'actual', 'category');
        $plans = ['keep' => $saved];
        if (((array) $simulation['levers']['planned']) !== []) {
            $plans['simulated'] = array_column($simulation['plannedToSave']['lines'], 'planned', 'category');
        }
        $lyPeriod = $this->calendar->findByDate(\DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year'));
        if ($lyPeriod !== null && $lyPeriod->getId() !== $period->getId()) {
            $ly = array_column($this->budgets->get($lyPeriod, $locale)['lines'], 'actual', 'category');
            $plans['lastYearActual'] = array_map('intval', $ly);
            $lyTotal = array_sum($ly);
            $total = array_sum($saved);
            if ($lyTotal > 0 && $total > 0) {
                $shares = [];
                foreach (GestionBudgetLine::CATEGORIES as $c) {
                    $shares[$c] = (int) floor($total * ($ly[$c] ?? 0) / $lyTotal);
                }
                $shares['purchases'] += $total - array_sum($shares); // l'arrondi va au stock : même total exactement
                $plans['lastYearShares'] = $shares;
            }
        }
        $levers = $body;
        $out = [];
        foreach ($plans as $key => $planned) {
            $out[$key] = $this->candidate($period, $levers, $key, $planned, $locale, $today);
        }
        // Achats réduits juste assez pour que la trésorerie après achats reste au-dessus du seuil (base : le prévu du gérant, sinon l'enregistré)
        $from = $out['simulated'] ?? $out['keep'];
        $threshold = $this->cash->settings()['threshold'];
        if ($from['cashAfterPurchases'] !== null && $from['cashAfterPurchases'] < $threshold) {
            $room = max(0, (int) $from['planned']['purchases'] - (int) ($actual['purchases'] ?? 0));
            $cut = min($threshold - $from['cashAfterPurchases'], $room);
            if ($cut > 0) {
                $planned = $from['planned'];
                $planned['purchases'] -= $cut;
                $out['cashSafe'] = $this->candidate($period, $levers, 'cashSafe', $planned, $locale, $today) + ['purchasesCut' => $cut, 'reachesThreshold' => $from['cashAfterPurchases'] + $cut >= $threshold];
            }
        }
        $keep = $out['keep'];
        foreach ($out as $key => &$c) {
            $c['delta'] = [
                'result' => $c['result'] - $keep['result'],
                'cashAfterPurchases' => $c['cashAfterPurchases'] === null || $keep['cashAfterPurchases'] === null ? null : $c['cashAfterPurchases'] - $keep['cashAfterPurchases'],
            ];
        }
        unset($c);

        return array_values(array_filter(array_map(fn ($k) => $out[$k] ?? null, self::KEYS)));
    }

    // ----- interne -----

    private function candidate(Collections $period, array $body, string $key, array $planned, string $locale, \DateTimeImmutable $today): array
    {
        $planned = array_combine(GestionBudgetLine::CATEGORIES, array_map(fn ($c) => max(0, (int) ($planned[$c] ?? 0)), GestionBudgetLine::CATEGORIES));
        $s = $this->simulation->simulate($period, ['planned' => $planned] + $body, $locale, $today)['simulated'];

        return [
            'key' => $key, 'label' => $this->translator->trans("simulation.candidates.$key", [], 'gestion', $locale), 'planned' => $planned,
            'result' => (int) $s['result'], 'cashAfterPurchases' => $s['cashAfterPurchases'], 'breakEven' => $s['breakEven']['revenue'] ?? null,
            'expenses' => (int) $s['expenses']['total'],
        ];
    }

    /** Avis des règles : cashSafe si la trésorerie l'impose, sinon le meilleur résultat (à égalité : garder) */
    private function fallback(array $candidates, string $locale): array
    {
        $by = array_column($candidates, null, 'key');
        $money = fn (?int $v) => $v === null ? '—' : $this->advices->format($v, 'money', $locale);
        if (isset($by['cashSafe'])) {
            $c = $by['cashSafe'];
            $from = $by['simulated'] ?? $by['keep'];

            return ['recommended' => 'cashSafe', 'textSource' => 'rules',
                'title' => $this->translator->trans('simulation.comment.cash_title', [], 'gestion', $locale),
                'text' => $this->translator->trans('simulation.comment.cash_text', ['%cash%' => $money($from['cashAfterPurchases']), '%threshold%' => $money($this->cash->settings()['threshold']), '%cut%' => $money($c['purchasesCut'])], 'gestion', $locale)];
        }
        $best = $by['keep'];
        foreach ($candidates as $c) {
            if ($c['result'] > $best['result']) {
                $best = $c;
            }
        }
        $key = $best['key'] === 'keep' ? 'keep_text' : 'best_text';

        return ['recommended' => $best['key'], 'textSource' => 'rules',
            'title' => $this->translator->trans('simulation.comment.title', [], 'gestion', $locale),
            'text' => $this->translator->trans("simulation.comment.$key", ['%label%' => $best['label'], '%result%' => $money($best['result']), '%delta%' => $money(abs($best['delta']['result']))], 'gestion', $locale)];
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: ?int} commentaire, statut de l'IA, usage */
    private function ai(Collections $period, array $simulation, array $candidates, array $fallback, string $locale, ?string $user): array
    {
        $f = fn ($v, string $u = 'money') => $v === null ? null : $this->advices->format($v, $u, $locale);
        $numbers = [];
        $add = function (mixed $v, string $unit) use (&$numbers): void {
            if ($v !== null) {
                $numbers[] = ['value' => $v, 'unit' => $unit];
            }
        };
        foreach ($candidates as $c) {
            foreach ($c['planned'] as $v) {
                $add($v, 'money');
            }
            foreach ([$c['result'], $c['cashAfterPurchases'], $c['breakEven'], $c['expenses'], $c['delta']['result'], $c['delta']['cashAfterPurchases'], $c['purchasesCut'] ?? null] as $v) {
                $add($v, 'money');
            }
        }
        foreach (['base', 'simulated'] as $sc) {
            $x = $simulation[$sc];
            foreach ([$x['sales']['total'], $x['sales']['projected'], $x['grossMargin'], $x['result'], $x['cashAfterPurchases'], $x['breakEven']['revenue'] ?? null, $x['stockEnd']['costValue'], $x['stockEnd']['saleValue']] as $v) {
                $add($v, 'money');
            }
            $add($x['tauxMarque'], 'percent');
            $add($x['stockEnd']['units'], 'count');
        }
        $add($simulation['levers']['pricePercent'], 'percent');
        $add($simulation['levers']['promotion']['percent'] ?? null, 'percent');
        $add($simulation['levers']['advertisingDelta'], 'money');
        $add($this->cash->settings()['threshold'], 'money');
        $payload = [
            'period' => ['name' => $period->getNomCollection(), 'daysRemaining' => $simulation['period']['daysRemaining']],
            'levers' => ['pricePercent' => $f($simulation['levers']['pricePercent'], 'percent'), 'promotionPercent' => $f($simulation['levers']['promotion']['percent'] ?? null, 'percent'), 'advertisingDelta' => $f($simulation['levers']['advertisingDelta'])],
            'scenario' => array_map(fn ($x) => ['sales' => $f($x['sales']['total']), 'grossMargin' => $f($x['grossMargin']), 'result' => $f($x['result']), 'cashAfterPurchases' => $f($x['cashAfterPurchases']), 'breakEven' => $f($x['breakEven']['revenue'] ?? null), 'stockEndAtCost' => $f($x['stockEnd']['costValue'])], ['base' => $simulation['base'], 'simulated' => $simulation['simulated']]),
            'cashThreshold' => $f($this->cash->settings()['threshold']),
            'candidates' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'planned' => array_map($f, $c['planned']), 'result' => $f($c['result']), 'cashAfterPurchases' => $f($c['cashAfterPurchases']),
                'breakEven' => $f($c['breakEven']), 'resultVsKeep' => $f($c['delta']['result']), 'cashVsKeep' => $f($c['delta']['cashAfterPurchases']), 'purchasesCut' => $f($c['purchasesCut'] ?? null)], $candidates),
            'fallback' => $fallback,
        ];
        return $this->askGuide($period, $payload, $numbers, array_column($candidates, 'key'), $fallback, $locale, $user);
    }

    /**
     * Avis du guide sur des candidats calculés par le backend (G1b-2, P6) : droit Aide IA, 1 crédit, sortie structurée { recommended, title, text },
     * nombres vérifiés contre `$numbers` (`[{ value, unit }]`), sinon avis des règles `$fallback` (+ rejected).
     *
     * @return array{0: array<string, mixed>, 1: string, 2: ?int} avis, statut de l'IA, usage
     */
    public function askGuide(Collections $period, array $payload, array $numbers, array $keys, array $fallback, string $locale, ?string $user, ?string $system = null): array
    {
        $code = (string) $this->tenants->getCurrentTenantCode();
        try {
            $allowed = $this->entitlements === null || $this->entitlements->isEnabled($code, 'ai.full') || $this->entitlements->isEnabled($code, 'ai.help');
        } catch (\Throwable) {
            $allowed = false;
        }
        if (!$allowed) {
            return [$fallback, 'not_included', null];
        }
        try {
            $usage = $this->quota->reserve('assistant', 'gestion_simulation_comment', self::COST, $user, sprintf('Avis du guide (%s)', $period->getNomCollection()), 120);
        } catch (LandingAiException $e) {
            return [$fallback, $e->getStatusCode() === 402 ? 'credits_exhausted' : 'error', null];
        }
        $stats = new LandingAiUsageStats(self::MODEL);
        $allowedNumbers = GestionAdviceService::allowedNumbers($numbers, 0);
        try {
            $response = $this->client->createMessage([
                'model' => self::MODEL, 'max_tokens' => 1500, 'system' => $system ?? $this->system($locale),
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['recommended', 'title', 'text'],
                    'properties' => ['recommended' => ['type' => 'string', 'enum' => $keys], 'title' => ['type' => 'string'], 'text' => ['type' => 'string']]]]],
            ], self::TIMEOUT);
            $stats->attempts++;
            $stats->add($response->usage ?? null);
            $out = null;
            foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
                if (($block->type ?? null) === 'text') {
                    $out = json_decode((string) ($block->text ?? ''), true);
                }
            }
            $this->quota->complete($this->emProvider->getEntityManager()->find(AiUsage::class, $usage->getId()), $stats, null);
            $title = mb_substr(GestionAdviceService::cleanText((string) ($out['title'] ?? '')) ?? '', 0, 80);
            $text = mb_substr(GestionAdviceService::cleanText((string) ($out['text'] ?? '')) ?? '', 0, 600);
            $unknown = GestionAdviceService::unknownNumbers($title . ' ' . $text, $allowedNumbers);
            if (!is_array($out) || !in_array($out['recommended'] ?? null, $keys, true) || $title === '' || $text === '' || $unknown !== []) {
                if ($this->debug) {
                    $this->logger->info('[Guide] avis du simulateur rejeté', ['unknown' => $unknown, 'title' => $title, 'text' => $text]);
                }

                return [$fallback + ['rejected' => true], 'ok', $usage->getId()];
            }

            return [['recommended' => $out['recommended'], 'title' => $title, 'text' => $text, 'textSource' => 'ai'], 'ok', $usage->getId()];
        } catch (\Throwable $e) {
            $this->logger->warning('[Guide] avis du simulateur en échec : ' . $e->getMessage());
            $fresh = $this->emProvider->getEntityManager()->find(AiUsage::class, $usage->getId());
            if ($fresh !== null && $fresh->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($fresh, $stats);
            }

            return [$fallback, 'error', $usage->getId()];
        }
    }

    private function system(string $locale): string
    {
        $language = $locale === 'en' ? 'anglais' : 'français';

        return "Tu es le guide de gestion d'un commerçant indépendant. On te donne une simulation de sa période et plusieurs répartitions de son budget, toutes calculées par le logiciel.\n"
            . "Ta tâche : recommander une répartition (`recommended`, une des clés fournies), avec un titre court (60 caractères au plus) et une explication de deux ou trois phrases (400 caractères), en $language, au vouvoiement.\n"
            . "Règles strictes :\n"
            . "- N'écris AUCUN nombre qui ne figure pas tel quel dans les données. Recopie les valeurs telles qu'elles sont écrites ; n'additionne, ne soustrais, ne multiplie rien.\n"
            . "- Le stock acheté n'est pas une charge du résultat : réduire les achats ne baisse jamais le résultat, cela protège la trésorerie (et laisse moins de stock à vendre). Ne présente jamais une baisse d'achats comme une perte de résultat.\n"
            . "- Si la trésorerie après achats passe sous le seuil, la priorité est de la protéger.\n"
            . "- Rien ne s'applique tout seul : le commerçant décide.";
    }
}
