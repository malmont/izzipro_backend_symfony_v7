<?php

namespace App\Gestion\Services;

use App\Gestion\Sequences\SequenceCatalog;
use App\Gestion\Sequences\SequenceFacts;
use App\Services\Platform\AdminStepUrlResolver;
use App\Services\Platform\SiteReadinessService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guide des séquences de l'application de gestion (lot IA-SEQ, 13/11/2026, `docs/sequences-gestion.md`) : « où j'en suis, quoi faire
 * ensuite ». Lit le catalogue (`SequenceCatalog`), le cheminement de mise en route (séquences à faire une fois) et les constats
 * (`SequenceFacts`, séquences qui reviennent). Aucune IA, aucune écriture, aucun cache propre : tout se recalcule à chaque lecture.
 */
class GestionSequenceService
{
    public const STATUS_READY = 'ready';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        private readonly SiteReadinessService $readiness,
        private readonly AdminStepUrlResolver $urls,
        private readonly SequenceFacts $facts,
        private readonly PeriodCalendar $calendar,
        private readonly GestionFiscalYearService $fiscalYear,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * GET /api/gestion/sequences : le résumé global, puis une ligne par séquence, sans le détail des étapes. Ordre du catalogue : les
     * séquences à faire une fois (ordre des groupes du cheminement), puis celles qui reviennent.
     *
     * @return array{summary: array<string, mixed>, sequences: list<array<string, mixed>>}
     */
    public function list(string $locale): array
    {
        $out = [];
        foreach ($this->once($locale) as $sequence) {
            unset($sequence['steps'], $sequence['instance']);
            $out[] = $sequence + ['instances' => []];
        }
        foreach (array_keys(SequenceCatalog::RECURRING) as $key) {
            $instances = array_map(fn (array $i) => $this->recurring($key, $i, $locale), $this->instances($key, $locale));
            $current = self::currentOf($instances);
            $out[] = [
                'key' => $key, 'kind' => SequenceCatalog::KIND_RECURRING, 'title' => $this->tr("sequences.$key.title", $locale), 'description' => $this->tr("sequences.$key.description", $locale),
                'translated' => true, 'status' => $current['status'], 'progress' => $current['progress'], 'next' => $current['next'],
                'instances' => array_map(fn (array $s) => $s['instance'] + ['status' => $s['status'], 'progress' => $s['progress'], 'next' => $s['next']], $instances),
            ];
        }

        return ['summary' => self::summarize($out), 'sequences' => $out];
    }

    /**
     * Résumé global, toutes séquences confondues (une séquence qui revient compte pour son instance courante) : totaux des étapes à
     * faire, nombre de séquences en cours, et LA prochaine chose à faire : la première étape indispensable à faire dans l'ordre des
     * séquences, sinon la première amélioration à faire, null quand tout est prêt. C'est la séquence que l'IA prendra en premier
     * (lot IA-AVANCE).
     *
     * @param list<array<string, mixed>> $sequences lignes de la liste, dans l'ordre du catalogue
     *
     * @return array{blockingTodo: int, improvementTodo: int, sequencesInProgress: int, next: ?array<string, mixed>}
     */
    public static function summarize(array $sequences): array
    {
        $next = null;
        foreach ([true, false] as $blocking) {
            foreach ($sequences as $sequence) {
                if ($next === null && ($sequence['next'] ?? null) !== null && $sequence['next']['blocking'] === $blocking) {
                    $current = array_values(array_filter($sequence['instances'] ?? [], fn (array $i) => $i['current'] ?? false))[0] ?? null;
                    $next = ['sequenceKey' => $sequence['key'], 'instance' => $current['id'] ?? null] + $sequence['next'];
                }
            }
        }

        return [
            'blockingTodo' => array_sum(array_map(fn (array $s) => $s['progress']['blockingTodo'], $sequences)),
            'improvementTodo' => array_sum(array_map(fn (array $s) => $s['progress']['improvementTodo'], $sequences)),
            'sequencesInProgress' => count(array_filter($sequences, fn (array $s) => $s['status'] === self::STATUS_IN_PROGRESS)),
            'next' => $next,
        ];
    }

    /**
     * GET /api/gestion/sequences/{key}?instance= : une séquence et ses étapes ; sans instance, l'instance courante.
     *
     * @return array<string, mixed>
     */
    public function detail(string $key, ?string $instanceId, string $locale): array
    {
        if (isset(SequenceCatalog::RECURRING[$key])) {
            $instances = $this->instances($key, $locale);
            $wanted = $instanceId === null || $instanceId === ''
                ? array_values(array_filter($instances, fn (array $i) => $i['current']))
                : array_values(array_filter($instances, fn (array $i) => $i['id'] === $instanceId));
            if ($wanted === []) {
                throw new GestionConflictException('INSTANCE_NOT_FOUND', 'Instance de séquence introuvable.', 404, ['sequence' => $key, 'instance' => $instanceId, 'instances' => array_column($instances, 'id')]);
            }

            return $this->recurring($key, $wanted[0], $locale);
        }
        foreach ($this->once($locale) as $sequence) {
            if ($sequence['key'] === $key) {
                return $sequence;
            }
        }
        throw new GestionConflictException('SEQUENCE_NOT_FOUND', 'Séquence introuvable.', 404, ['sequence' => $key]);
    }

    // ---- séquences à faire une fois : les groupes du cheminement ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> une séquence par groupe du cheminement accordé au site, étapes comprises */
    private function once(string $locale): array
    {
        $readiness = $this->readiness->forCurrentSite($locale);
        $out = [];
        foreach ($readiness['groups'] ?? [] as $group) {
            $steps = [];
            foreach ($readiness['steps'] as $step) {
                if (($step['group'] ?? null) === $group['key']) { // les étapes « Obtenir » portent un groupe non accordé : jamais dans `groups`
                    $steps[] = $this->onceStep($step, $locale);
                }
            }
            $out[] = [
                'key' => SequenceCatalog::onceKey((string) $group['key']), 'kind' => SequenceCatalog::KIND_ONCE, 'group' => $group['key'],
                'title' => (string) $group['label'], 'description' => $this->tr('sequences.once.description', $locale, ['%label%' => (string) $group['label']]),
                'translated' => in_array($group['key'], SequenceCatalog::TRANSLATED_GROUPS, true),
            ] + self::summary($steps) + ['instance' => null, 'steps' => $steps];
        }

        return $out;
    }

    /** Étape du cheminement dans la forme du guide : mêmes clé, textes, statut et caractère indispensable */
    private function onceStep(array $step, string $locale): array
    {
        $action = $step['action'] ?? [];
        $appScreen = $step['appScreen'] ?? null;
        $screen = $appScreen !== null
            ? self::screen(SequenceCatalog::WHERE_APP, (string) $appScreen, SequenceCatalog::ONCE_SCREEN_PARAMS[$step['key']] ?? [], null)
            : self::screen((string) ($step['where'] ?? 'backend'), (string) ($action['screen'] ?? $action['feature'] ?? $action['type'] ?? ''), [], $this->urls->stepUrl($step));
        $figures = [];
        foreach ($step['figures'] ?? [] as $key => $value) {
            $figures[] = [(string) $key, $value, is_int($value) || is_float($value) ? 'count' : 'text'];
        }

        return [
            'key' => (string) $step['key'], 'title' => (string) $step['title'], 'description' => (string) $step['description'], 'status' => (string) $step['status'],
            'blocking' => (bool) ($step['blocking'] ?? true), 'screen' => $screen,
            'action' => self::action(SequenceCatalog::actionOf((string) $step['key']), SequenceCatalog::ONCE_ACTION_PARAMS[$step['key']] ?? []),
            'figures' => $this->figures($figures, $locale),
        ];
    }

    // ---- séquences qui reviennent --------------------------------------------------------------------------------------------------

    /**
     * Instances d'une séquence qui revient, la courante marquée `current`. Lecture seule : une période que le calendrier n'a pas encore
     * créée (il crée la suivante avec celle en cours, à l'ouverture de l'application) est décrite ici sans être créée, `periodId` null.
     *
     * @return list<array<string, mixed>>
     */
    private function instances(string $key, string $locale): array
    {
        $today = $this->calendar->today();
        if ($key === SequenceCatalog::PERIOD_PREPARE) {
            $frequency = $this->calendar->frequency();
            $current = PeriodCalendar::definition($today, $frequency);
            $next = PeriodCalendar::definition($current['end']->modify('+1 day'), $frequency);

            return array_map(function (array $definition) use ($current, $locale) {
                $row = $this->facts->periodRow($definition['key']);

                return [
                    'id' => $definition['key'],
                    'label' => PeriodCalendar::displayNameFor($row['nom_collection'] ?? $definition['name'], 'period', $definition['key'], $definition['start'], $locale),
                    'from' => $definition['start']->format('Y-m-d'), 'to' => $definition['end']->format('Y-m-d'), 'current' => $definition['key'] === $current['key'],
                    'periodId' => $row !== null ? (int) $row['id'] : null,
                ];
            }, [$current, $next]);
        }
        if ($key === SequenceCatalog::FISCAL_YEAR_CLOSE) {
            $month = $this->fiscalYear->startMonth();
            $year = GestionFiscalYearService::startYearOf($today, $month) - 1; // le dernier exercice terminé
            [$from, $to] = GestionFiscalYearService::bounds($year, $month);

            return [['id' => (string) $year, 'label' => GestionFiscalYearService::label($year, $month), 'from' => $from, 'to' => $to, 'current' => true, 'year' => $year]];
        }
        // Reprise d'historique : une seule instance, celle du site ; créneau de la reprise appliquée, sinon des premières ventes à la veille de la période en cours
        $reprise = $this->facts->reprise();
        $firstSale = $this->facts->firstSaleDay();
        $eve = PeriodCalendar::definition($today, $this->calendar->frequency())['start']->modify('-1 day')->format('Y-m-d');
        [$from, $to] = $reprise !== null ? [$reprise['from'], $reprise['to']] : ($firstSale !== null && $firstSale <= $eve ? [$firstSale, $eve] : [null, null]);

        return [['id' => 'site', 'label' => $this->tr('sequences.' . SequenceCatalog::HISTORY_RECOVERY . '.instance', $locale), 'from' => $from, 'to' => $to, 'current' => true,
            'reprise' => $reprise, 'firstSale' => $firstSale, 'firstPurchase' => $this->facts->firstPurchaseDay()]];
    }

    /** Une séquence qui revient pour une instance : étapes calculées par les constats du catalogue */
    private function recurring(string $key, array $instance, string $locale): array
    {
        $steps = [];
        $gated = false; // la première étape dit si la séquence a un objet : sans objet, toutes les étapes le sont
        foreach (SequenceCatalog::RECURRING[$key]['steps'] as $stepKey => $definition) {
            $fact = $this->facts->{$definition['fact']}($instance);
            $gated = $gated || ($steps === [] && !$fact['applicable']);
            $status = $gated || !$fact['applicable'] ? 'not_applicable' : ($fact['done'] ? 'done' : (($definition['optional'] ?? false) ? 'optional' : 'todo'));
            $params = [];
            foreach ($fact['figures'] as [$name, $value]) {
                $params["%$name%"] = (string) $value;
            }
            $steps[] = [
                'key' => $stepKey, 'title' => $this->tr("sequences.$key.steps.$stepKey.title", $locale),
                'description' => $this->tr("sequences.$key.steps.$stepKey." . ($status === 'done' ? 'done' : 'todo'), $locale, $params),
                'status' => $status, 'blocking' => $definition['blocking'],
                'screen' => self::screen(SequenceCatalog::WHERE_APP, $definition['screen'], self::instanceParams($key, $instance) + $fact['params'], null),
                'action' => self::action($definition['action'], $definition['actionParams'] ?? []),
                'figures' => $this->figures($fact['figures'], $locale),
            ];
        }
        $public = array_intersect_key($instance, array_flip(['id', 'label', 'from', 'to', 'current', 'periodId']));

        return ['key' => $key, 'kind' => SequenceCatalog::KIND_RECURRING, 'title' => $this->tr("sequences.$key.title", $locale), 'description' => $this->tr("sequences.$key.description", $locale),
            'translated' => true] + self::summary($steps) + ['instance' => $public, 'steps' => $steps];
    }

    /** Paramètres d'écran propres à l'instance : la période, ou l'exercice */
    private static function instanceParams(string $key, array $instance): array
    {
        return match ($key) {
            SequenceCatalog::PERIOD_PREPARE => ['periodId' => $instance['periodId'], 'periodKey' => $instance['id']],
            SequenceCatalog::FISCAL_YEAR_CLOSE => ['year' => $instance['year']],
            default => [],
        };
    }

    // ---- outils --------------------------------------------------------------------------------------------------------------------

    /**
     * Statut, avancement et étape suivante d'une suite d'étapes. L'avancement ne compte que les étapes faites ou à faire (comme le
     * cheminement) ; l'étape suivante est la première indispensable à faire, sinon la première amélioration à faire.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return array{status: string, progress: array{done: int, total: int, blockingTodo: int, improvementTodo: int, percent: int}, next: ?array<string, mixed>}
     */
    private static function summary(array $steps): array
    {
        $done = count(array_filter($steps, fn (array $s) => $s['status'] === 'done'));
        $todo = array_values(array_filter($steps, fn (array $s) => $s['status'] === 'todo'));
        $blocking = array_values(array_filter($todo, fn (array $s) => $s['blocking']));
        $improvement = array_values(array_filter($todo, fn (array $s) => !$s['blocking']));
        $total = $done + count($todo);
        $next = $blocking[0] ?? $improvement[0] ?? null;
        $applicable = array_filter($steps, fn (array $s) => $s['status'] !== 'not_applicable') !== [];

        return [
            'status' => !$applicable ? self::STATUS_NOT_APPLICABLE : ($blocking === [] ? self::STATUS_READY : self::STATUS_IN_PROGRESS),
            'progress' => ['done' => $done, 'total' => $total, 'blockingTodo' => count($blocking), 'improvementTodo' => count($improvement), 'percent' => $total > 0 ? (int) round($done * 100 / $total) : 100],
            'next' => $next === null ? null : ['stepKey' => $next['key'], 'title' => $next['title'], 'blocking' => $next['blocking'], 'screen' => $next['screen'], 'action' => $next['action']],
        ];
    }

    /** @param list<array<string, mixed>> $sequences séquences d'une même clé, une par instance */
    private static function currentOf(array $sequences): array
    {
        foreach ($sequences as $sequence) {
            if ($sequence['instance']['current']) {
                return $sequence;
            }
        }

        return $sequences[0];
    }

    /**
     * @return array{key: string, preparable: bool, params: object} référence de l'action, si la conversation sait la préparer, et les
     *                                                                paramètres à donner à l'ouverture de son fil (`POST /api/gestion/conversations { action, params }`)
     */
    private static function action(string $key, array $params = []): array
    {
        return ['key' => $key, 'preparable' => SequenceCatalog::preparable($key), 'params' => (object) $params];
    }

    /** @param array<string, mixed> $params */
    private static function screen(string $where, string $key, array $params, ?string $url): array
    {
        return ['where' => $where, 'key' => $key, 'params' => (object) $params, 'url' => $url];
    }

    /**
     * @param list<array{0: string, 1: mixed, 2: string}> $figures
     *
     * @return list<array{key: string, label: string, value: mixed, unit: string}>
     */
    private function figures(array $figures, string $locale): array
    {
        return array_map(fn (array $f) => ['key' => $f[0], 'label' => $this->tr('sequences.figures.' . $f[0], $locale, [], $f[0]), 'value' => $f[1], 'unit' => $f[2]], $figures);
    }

    /** @param array<string, string> $params */
    private function tr(string $id, string $locale, array $params = [], ?string $fallback = null): string
    {
        $text = $this->translator->trans($id, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');

        return $text === $id && $fallback !== null ? $fallback : $text;
    }
}
