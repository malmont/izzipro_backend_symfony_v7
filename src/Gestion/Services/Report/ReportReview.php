<?php

namespace App\Gestion\Services\Report;

use App\Entity\Collections;
use App\Gestion\Analysis\AnalysisCatalog;
use App\Gestion\Analysis\RangeLabel;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot DOC-2 (15/11/2026, `docs/rapports-gestion.md`) : le contenu d'un bilan de période ou d'année. Le serveur lance lui-même les
 * analyses nommées de la console (`AnalysisCatalog`) : un chiffre du bilan est donc celui de l'écran, aux mêmes règles. Aucun modèle
 * n'est appelé et rien n'est écrit.
 *
 * Le contenu rendu est gardé avec le rapport : le worker met en page ce qui a été calculé à la demande, sans rien recalculer. Forme :
 * `{ title, kindLabel, range, notice, blocks, analyses, selected, computedAt, resolved }`. `blocks` a la forme des blocs de la console
 * (`figures`, `table`, `text`) plus `heading` (le titre d'une partie) ; `selected` nomme les analyses lancées, pour que l'avis de la
 * console porte sur les mêmes chiffres.
 */
class ReportReview
{
    /** Analyses d'un bilan de période, dans l'ordre du papier ; le stock ne vaut que pour la période en cours (c'est celui d'aujourd'hui) */
    private const PERIOD_ANALYSES = ['period.result', 'sales.overview', 'budget.status'];

    public function __construct(
        private readonly AnalysisCatalog $analyses,
        private readonly PeriodCalendar $calendar,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TranslatorInterface $translator,
        private readonly StoreClock $clock,
    ) {
    }

    /** @return array<string, mixed>|null le bilan d'une période, ou null si elle n'existe pas sur le site */
    public function period(int $periodId, string $locale): ?array
    {
        if (!isset($this->analyses->accept('period.result', ['periodId' => $periodId])['periodId'])) {
            return null;
        }
        $period = $this->emProvider->getEntityManager()->find(Collections::class, $periodId);
        $today = $this->calendar->today()->format('Y-m-d');
        $from = $period->getStartDateCollection()->format('Y-m-d');
        $to = $period->getEndDateCollection()->format('Y-m-d');
        $selected = array_map(static fn (string $key): array => ['key' => $key, 'params' => ['periodId' => $periodId]], self::PERIOD_ANALYSES);
        if ($from <= $today && $today <= $to) {
            $selected[] = ['key' => 'stock.overview', 'params' => []];
        }
        $closedAt = $this->calendar->closedAt($period);
        if ($closedAt !== null && $period->getClosedAt() !== null) {
            $closedAt = $closedAt->setTimezone($this->clock->timezone()); // une clôture faite à la main est un instant : son jour est celui du magasin
        }
        $notice = $closedAt === null
            ? $this->tr('provisional_period', $locale, ['%date%' => RangeLabel::day($today, $locale)])
            : $this->tr('closed_period', $locale, ['%date%' => RangeLabel::day($closedAt->format('Y-m-d'), $locale)]);

        return $this->content('period', $this->tr('period_title', $locale, ['%name%' => PeriodCalendar::displayName($period, $locale)]), RangeLabel::of($from, $to, $locale), $notice, $selected, ['periodId' => $periodId], $locale);
    }

    /** @return array<string, mixed>|null le bilan d'une année (l'exercice en cours sans `year`), ou null si l'année est hors des bornes */
    public function year(?int $year, string $locale): ?array
    {
        $params = [];
        if ($year !== null) {
            $params = $this->analyses->accept('year.overview', ['year' => $year]) ?? [];
            if (!isset($params['year'])) {
                return null;
            }
        }
        try {
            $content = $this->content('year', '', '', null, [['key' => 'year.overview', 'params' => $params]], [], $locale);
        } catch (GestionValidationException) {
            return null; // une année que l'écran Année refuse (au-delà de l'exercice en cours)
        }
        $period = $content['analyses'][0]['period'] ?? [];
        $resolved = (int) ($content['analyses'][0]['params']['year'] ?? ($year ?? 0));
        $today = $this->calendar->today()->format('Y-m-d');
        $content['title'] = $this->tr('year_title', $locale, ['%name%' => (string) ($period['label'] ?? $resolved)]);
        $content['range'] = isset($period['from'], $period['to']) ? RangeLabel::of((string) $period['from'], (string) $period['to'], $locale) : '';
        $content['notice'] = isset($period['to']) && $period['to'] >= $today ? $this->tr('provisional_year', $locale, ['%date%' => RangeLabel::day($today, $locale)]) : null;
        $content['resolved'] = ['year' => $resolved];
        // l'avis de la console porte sur la même année, même si l'exercice change entre la demande et sa rédaction
        $content['selected'] = [['key' => 'year.overview', 'params' => ['year' => $resolved]]];

        return $content;
    }

    /** Ce qui fait qu'un bilan est « le même » : son texte et ses chiffres, sans l'instant du calcul */
    public static function key(array $content): string
    {
        return hash('sha256', (string) json_encode([$content['title'], $content['range'], $content['notice'], $content['blocks'], $content['analyses']]));
    }

    /**
     * @param list<array{key: string, params: array<string, mixed>}> $selected
     * @param array<string, mixed>                                   $resolved
     * @return array<string, mixed>
     */
    private function content(string $kind, string $title, string $range, ?string $notice, array $selected, array $resolved, string $locale): array
    {
        $blocks = [];
        $listed = [];
        foreach ($selected as ['key' => $key, 'params' => $params]) {
            $r = $this->analyses->run($key, $params, $locale);
            $blocks[] = ['type' => 'heading', 'title' => $r['title'], 'note' => is_array($r['period']) ? (string) ($r['period']['label'] ?? '') : ''];
            if ($r['figures'] !== []) {
                $blocks[] = ['type' => 'figures', 'items' => array_map(static fn (array $f): array => $f + ['source' => $r['source']], $r['figures'])];
            }
            if ($r['rows'] !== []) {
                $rows = array_map(fn (array $row): array => $this->analyses->writtenRow($row, $locale), $r['rows']);
                if ($key === 'year.overview') {
                    usort($rows, static fn (array $a, array $b): int => ($a['monthKey'] ?? '') <=> ($b['monthKey'] ?? '')); // sur le papier, les mois dans l'ordre de l'exercice ; le rang reste en colonne
                }
                $blocks[] = ['type' => 'table', 'title' => $this->tr('tables.' . str_replace('.', '_', $key), $locale), 'columns' => $this->analyses->columns($key, $locale), 'rows' => $rows, 'more' => $r['more'], 'source' => $r['source']];
            }
            if ($r['figures'] === [] && $r['rows'] === []) {
                $blocks[] = ['type' => 'text', 'text' => $this->tr('empty_part', $locale)];
            }
            $listed[] = ['key' => $key, 'title' => $r['title'], 'params' => $r['source']['params'], 'source' => $r['source'], 'period' => $r['period']];
        }

        return ['title' => $title, 'kindLabel' => $this->tr($kind . '_kind', $locale), 'range' => $range, 'notice' => $notice, 'blocks' => $blocks, 'analyses' => $listed,
            'selected' => $selected, 'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'resolved' => $resolved];
    }

    /** @param array<string, string> $params */
    private function tr(string $key, string $locale, array $params = []): string
    {
        return $this->translator->trans('reports.' . $key, $params, 'gestion', $locale === 'en' ? 'en' : 'fr');
    }
}
