<?php

namespace App\Gestion\Services;

use App\Entity\AiUsage;
use App\Entity\Collections;
use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rédaction des conseils du guide par l'IA (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md` § 6), sur le worker `gestion_ai` : droit d'usage
 * « Aide IA » ou « IA complète », 1 crédit IA du site par rédaction ; seul un JSON agrégé part (aucun client, fournisseur, utilisateur, nom de
 * campagne ou de voyage ; salaires en total) ; chaque texte rendu est vérifié (aucun nombre hors des sources du conseil, sinon texte de repli).
 */
class AdviceWriter
{
    public const MODEL = 'claude-sonnet-5-5';
    public const COST = 1;
    public const TIMEOUT = 90.0;
    public const MAX_TITLE = 80;
    public const MAX_TEXT = 400;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingAiClientInterface $client,
        private readonly LandingAiQuotaService $quota,
        private readonly GestionAdviceService $advices,
        private readonly AdviceRules $rules,
        private readonly PeriodResultCalculator $results,
        private readonly GestionCashService $cash,
        private readonly PeriodCalendar $calendar,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Platform\EntitlementService $entitlements = null,
        #[Autowire('%kernel.debug%')] private readonly bool $debug = false,
    ) {
    }

    /** @return string statut final de la génération */
    public function write(int $runId, string $tenantCode): string
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $run = $conn->fetchAssociative('SELECT * FROM gestion_advice_run WHERE id = ?', [$runId]);
        if ($run === false || $run['status'] !== 'pending') {
            return $run['status'] ?? 'missing';
        }
        $finish = function (string $status, ?int $usageId = null, int $rejected = 0, ?string $headline = null, ?string $locale = null) use ($conn, $runId): string {
            $conn->executeStatement('UPDATE gestion_advice_run SET status = ?, ai_usage_id = ?, rejected = ?, headline = ?, headline_locale = ?, finished_at = NOW() WHERE id = ?',
                [$status, $usageId, $rejected, $headline, $locale, $runId]);

            return $status;
        };
        if (!$this->aiIncluded($tenantCode)) {
            return $finish('not_included');
        }
        $period = $this->emProvider->getEntityManager()->find(Collections::class, (int) $run['period_id']);
        $rows = $conn->fetchAllAssociative("SELECT * FROM gestion_advice WHERE period_id = ? AND active AND status = 'new' ORDER BY rank, id", [$run['period_id']]);
        if ($period === null || $rows === []) {
            return $finish('ok');
        }
        try {
            $usage = $this->quota->reserve('assistant', 'gestion_advice', self::COST, $run['created_by'], sprintf('Guide de la gestion (%s)', $period->getNomCollection()), 600);
        } catch (LandingAiException $e) {
            return $finish($e->getStatusCode() === 402 ? 'credits_exhausted' : 'error');
        }
        $stats = new LandingAiUsageStats(self::MODEL);
        $locale = $this->advices->siteLanguage();
        try {
            [$payload, $allowed, $contextNumbers] = $this->payload($period, $rows, $locale);
            $response = $this->client->createMessage([
                'model' => self::MODEL, 'max_tokens' => 4000, 'system' => $this->system($locale),
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ], self::TIMEOUT);
            $stats->attempts++;
            $stats->add($response->usage ?? null);
            $out = null;
            foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
                if (($block->type ?? null) === 'text') {
                    $out = json_decode((string) ($block->text ?? ''), true);
                }
            }
            if (!is_array($out)) {
                throw new \RuntimeException('Réponse illisible (' . ($response->stop_reason ?? '?') . ')');
            }
            $rejected = 0;
            $byId = array_column($rows, null, 'id');
            foreach (is_array($out['advices'] ?? null) ? $out['advices'] : [] as $item) {
                $id = (int) ($item['id'] ?? 0);
                if (!isset($byId[$id])) {
                    continue;
                }
                $title = mb_substr(GestionAdviceService::cleanText((string) ($item['title'] ?? '')) ?? '', 0, self::MAX_TITLE);
                $text = mb_substr(GestionAdviceService::cleanText((string) ($item['text'] ?? '')) ?? '', 0, self::MAX_TEXT);
                $unknown = GestionAdviceService::unknownNumbers($title . ' ' . $text, $allowed[$id]);
                if ($title === '' || $text === '' || $unknown !== []) {
                    ++$rejected;
                    if ($this->debug) {
                        $this->logger->info('[Guide] texte rejeté', ['adviceId' => $id, 'unknown' => $unknown, 'title' => $title, 'text' => $text]);
                    }
                    continue;
                }
                $conn->executeStatement('UPDATE gestion_advice SET ai_title = ?, ai_text = ?, ai_locale = ?, updated_at = NOW() WHERE id = ?', [$title, $text, $locale, $id]);
            }
            $rank = 1;
            foreach (array_values(array_unique(array_merge(array_values(array_filter(array_map('intval', is_array($out['order'] ?? null) ? $out['order'] : []), fn ($id) => isset($byId[$id]))), array_map('intval', array_keys($byId))))) as $id) {
                $conn->executeStatement('UPDATE gestion_advice SET rank = ? WHERE id = ?', [$rank++, $id]);
            }
            $rawHeadline = trim((string) ($out['headline'] ?? ''));
            $headline = GestionAdviceService::cleanText($rawHeadline) ?? '';
            if ($rawHeadline !== '' && $headline === '') {
                ++$rejected; // caractères de structure restés dans la phrase
            }
            $all = array_merge($contextNumbers, ...array_values($allowed));
            if ($headline !== '' && GestionAdviceService::unknownNumbers($headline, $all) !== []) {
                ++$rejected;
                if ($this->debug) {
                    $this->logger->info('[Guide] phrase de synthèse rejetée', ['unknown' => GestionAdviceService::unknownNumbers($headline, $all), 'headline' => $headline]);
                }
                $headline = '';
            }
            $this->quota->complete($this->emProvider->getEntityManager()->find(AiUsage::class, $usage->getId()), $stats, null);

            return $finish('ok', $usage->getId(), $rejected, $headline === '' ? null : mb_substr($headline, 0, self::MAX_TEXT), $headline === '' ? null : $locale);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[Guide] rédaction du run %d en échec : %s', $runId, $e->getMessage()));
            $fresh = $this->emProvider->getEntityManager()->find(AiUsage::class, $usage->getId());
            if ($fresh !== null && $fresh->getStatus() === AiUsage::STATUS_RESERVED) {
                $this->quota->release($fresh, $stats);
            }

            return $finish('error', $usage->getId());
        }
    }

    /**
     * Charge envoyée : contexte de la période (moments, résultat, autres revenus, trésorerie, mouvements prévus en totaux par catégorie) et
     * conseils candidats (chiffres déjà formatés, texte de repli). Aucune donnée personnelle.
     *
     * @return array{0: array<string, mixed>, 1: array<int, list<float>>, 2: list<float>}
     */
    public function payload(Collections $period, array $rows, string $locale): array
    {
        $today = $this->calendar->today();
        $m = $this->rules->moments($period, $today);
        $result = $this->results->compute($period, null);
        $cash = $this->cash->projection(30, $locale, $today);
        $f = fn ($v, $u) => $this->advices->format($v, $u, $locale);
        $context = [
            'period' => ['name' => $period->getNomCollection(), 'start' => $f($period->getStartDateCollection()->format('Y-m-d'), 'date'), 'end' => $f($period->getEndDateCollection()->format('Y-m-d'), 'date'),
                'daysTotal' => $m['daysTotal'], 'daysRemaining' => $m['daysRemaining']],
            'moments' => $m['moments'],
            'result' => ['revenue' => $f((int) $result['sales']['revenue'], 'money'), 'grossMargin' => $f((int) $result['result']['gross'], 'money'), 'net' => $f((int) $result['result']['net'], 'money')],
            'otherRevenue' => ['rentals' => $f((int) $result['otherRevenue']['rentals'], 'money'), 'subscriptions' => $f((int) $result['otherRevenue']['subscriptions'], 'money')],
            // lot FF2 : frais fixes de la période (FF1), totaux par catégorie seulement, jamais une ligne ni un nom (salaires compris)
            'fixedCosts' => ['total' => $f((int) ($result['fixedCosts']['total'] ?? 0), 'money'), 'netAfterFixed' => $f((int) ($result['result']['netAfterFixed'] ?? $result['result']['net']), 'money'),
                'byCategory' => array_map(fn ($c) => ['category' => $c['label'], 'amount' => $f((int) $c['amount'], 'money')], $result['fixedCosts']['byCategory'] ?? [])],
            'cash' => $cash['balance'] === null ? null : ['status' => $cash['status'], 'lowest14Days' => $f($cash[$cash['alertSeries']]['low14']['amount'], 'money'),
                'lowestDate' => $f($cash[$cash['alertSeries']]['low14']['date'], 'date'), 'threshold' => $f($cash['settings']['threshold'], 'money')],
            'plannedMovements30Days' => array_values(array_map(fn ($c) => ['category' => $c['label'], 'in' => $f($c['in'], 'money'), 'out' => $f($c['out'], 'money')],
                array_filter($cash['byCategory'], fn ($c) => $c['category'] !== GestionCashService::RECORDED))),
        ];
        $contextNumbers = GestionAdviceService::allowedNumbers([
            ['value' => (int) $result['sales']['revenue'], 'unit' => 'money'], ['value' => (int) $result['result']['gross'], 'unit' => 'money'], ['value' => (int) $result['result']['net'], 'unit' => 'money'],
            ['value' => (int) $result['otherRevenue']['rentals'], 'unit' => 'money'], ['value' => (int) $result['otherRevenue']['subscriptions'], 'unit' => 'money'],
            ['value' => (int) ($result['fixedCosts']['total'] ?? 0), 'unit' => 'money'], ['value' => (int) ($result['result']['netAfterFixed'] ?? $result['result']['net']), 'unit' => 'money'],
            ...array_map(fn ($c) => ['value' => (int) $c['amount'], 'unit' => 'money'], $result['fixedCosts']['byCategory'] ?? []),
            ['value' => $m['daysTotal'], 'unit' => 'count'], ['value' => $m['daysRemaining'], 'unit' => 'count'],
            ['value' => $period->getStartDateCollection()->format('Y-m-d'), 'unit' => 'date'], ['value' => $period->getEndDateCollection()->format('Y-m-d'), 'unit' => 'date'],
            ...($cash['balance'] === null ? [] : [['value' => $cash[$cash['alertSeries']]['low14']['amount'], 'unit' => 'money'], ['value' => $cash[$cash['alertSeries']]['low14']['date'], 'unit' => 'date'], ['value' => $cash['settings']['threshold'], 'unit' => 'money']]),
            ...array_merge(...array_map(fn ($c) => [['value' => $c['in'], 'unit' => 'money'], ['value' => $c['out'], 'unit' => 'money']], $cash['byCategory'])),
        ], 0);
        // Conseils appliqués de la période dont la mesure est complète (lot G1b-1) : l'IA peut s'appuyer sur leurs résultats
        $applied = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative("SELECT * FROM gestion_advice WHERE period_id = ? AND status = 'done' ORDER BY done_at DESC LIMIT 8", [$period->getId()]) as $done) {
            $a = $this->advices->toArray($done, $locale);
            if (($a['measurement']['complete'] ?? false) !== true) {
                continue;
            }
            $applied[] = ['kind' => $a['kind'], 'appliedOn' => $f((string) $a['appliedOn'], 'date'), 'marginChange' => $f($a['measurement']['change']['grossMargin'], 'money'), 'salesChange' => $f($a['measurement']['change']['revenue'], 'money'), 'days' => AdviceMeasure::WINDOW_DAYS];
            $contextNumbers = array_merge($contextNumbers, GestionAdviceService::allowedNumbers([['value' => (string) $a['appliedOn'], 'unit' => 'date'], ['value' => $a['measurement']['change']['revenue'], 'unit' => 'money']], $a['measurement']['change']['grossMargin']));
        }
        $context['appliedResults'] = $applied;
        $advices = [];
        $allowed = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $sources = json_decode((string) $r['sources'], true) ?: [];
            $params = json_decode((string) $r['params'], true) ?: [];
            $figures = json_decode((string) $r['figures'], true) ?: [];
            [$title, $text] = $this->advices->fallback((string) $r['kind'], $sources, $params, (int) $r['effect_cents'], $locale);
            $advice = $this->advices->toArray($r, $locale);
            $advices[] = [
                'id' => $id, 'kind' => $r['kind'], 'moment' => $r['moment'], 'confidence' => $r['confidence'],
                'effect' => ['amount' => $f(abs((int) $r['effect_cents']), 'money'), 'direction' => (int) $r['effect_cents'] >= 0 ? 'gain' : 'cost', 'label' => $advice['effect']['label']],
                'sources' => array_map(fn ($s, $raw) => ['label' => $s['label'], 'value' => $f($raw['value'], $raw['unit'])], $advice['sources'], $sources),
                'products' => array_values(array_filter(array_map(fn ($x) => $x['name'] ?? null, $figures))),
                'fallback' => ['title' => $title, 'text' => $text],
            ];
            $allowed[$id] = GestionAdviceService::allowedNumbers($sources, (int) $r['effect_cents'], count($figures));
        }

        return [['context' => $context, 'advices' => $advices], $allowed, $contextNumbers];
    }

    /** Sortie structurée : sans union de types (limite de 16 de l'API), tout requis */
    public static function schema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['headline', 'advices', 'order'],
            'properties' => [
                'headline' => ['type' => 'string'],
                'advices' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'title', 'text'],
                    'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'text' => ['type' => 'string']]]],
                'order' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ];
    }

    private function system(string $locale): string
    {
        $language = $locale === 'en' ? 'anglais' : 'français';

        return "Tu es le guide de gestion d'un commerçant indépendant. On te donne des conseils déjà détectés et chiffrés par le logiciel, avec le contexte de la période.\n"
            . "Ta tâche : pour chaque conseil, écrire un titre court (60 caractères au plus) et un texte de deux phrases au plus (280 caractères), en $language, au vouvoiement, concrets et sans jargon ; "
            . "classer les conseils du plus utile au moins utile dans `order` ; écrire `headline`, une phrase qui résume la période en tenant compte de la trésorerie, des locations, des abonnements, des frais fixes (`fixedCosts`, déjà répartis sur la période) et des mouvements prévus.\n"
            . "Règles strictes :\n"
            . "- N'écris AUCUN nombre (montant, pourcentage, quantité, date) qui ne figure pas tel quel dans les sources ou l'effet du conseil (pour `headline` : dans le contexte). Recopie les valeurs telles qu'elles sont écrites. N'additionne, ne soustrais, ne multiplie rien.\n"
            . "- N'invente aucun conseil : réponds seulement pour les identifiants fournis.\n"
            . "- Un effet est une estimation : dis « environ » ou « estimé ».\n"
            . "- Aucune donnée personnelle ; les noms d'articles peuvent être repris.\n"
            . "- Rien ne s'applique tout seul : propose, le commerçant décide.";
    }

    private function aiIncluded(string $tenantCode): bool
    {
        if ($this->entitlements === null) {
            return true;
        }
        try {
            return $this->entitlements->isEnabled($tenantCode, 'ai.full') || $this->entitlements->isEnabled($tenantCode, 'ai.help');
        } catch (\Throwable) {
            return false;
        }
    }
}
