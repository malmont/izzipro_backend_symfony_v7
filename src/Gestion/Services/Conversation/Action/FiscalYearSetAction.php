<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Entity\Collections;
use App\Gestion\Services\GestionFiscalYearService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `fiscal_year.set` : choisir le mois de début de l'exercice du site. L'aperçu montre l'exercice qui en résulte et, si le mois ne tombe
 * pas au début d'une période du site, quelle période serait coupée et à quelle date. Écrit par le service du réglage existant
 * (`PUT /api/gestion/reglages/exercice`). Annulation : remettre le mois d'avant, s'il n'a pas été changé depuis.
 */
class FiscalYearSetAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly GestionFiscalYearService $fiscal, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'fiscal_year.set';
    }

    public function build(array $answers, string $locale): array
    {
        $current = $this->fiscal->startMonth();
        $aligned = $this->fiscal->alignedMonths();
        $options = array_map(fn (int $m) => ['value' => $m, 'label' => ucfirst(self::month($m, $locale)), 'aligned' => in_array($m, $aligned, true), 'current' => $m === $current], range(1, 12));
        $answer = $answers['startMonth'] ?? null;
        $issues = $answer !== null && (int) $answer === $current ? [['path' => 'startMonth', 'code' => 'UNCHANGED']] : []; // rien à confirmer : c'est déjà le mois de l'exercice

        return ['questions' => [$this->question('startMonth', true, ['type' => 'choice'], $options, $answer, $locale)], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            $this->fail($index, 'MUST_BE_INTEGER');
        }

        return $value >= 1 && $value <= 12 ? $value : $this->fail($index, 'OUT_OF_RANGE');
    }

    public function preview(array $answers, string $locale): array
    {
        $before = $this->fiscal->startMonth();
        $after = (int) $answers['startMonth'];
        $today = $this->calendar->today();
        $year = GestionFiscalYearService::startYearOf($today, $after);
        [$from, $to] = GestionFiscalYearService::bounds($year, $after);
        // Mois non aligné : la période du site qui contient le premier jour de l'exercice commence avant lui, l'exercice la coupe en deux
        $period = PeriodCalendar::definition(new \DateTimeImmutable($from), $this->calendar->frequency());
        $aligned = $period['start']->format('Y-m-d') === $from;
        $warning = null;
        if (!$aligned) {
            $name = PeriodCalendar::displayNameFor($period['name'], Collections::KIND_PERIOD, $period['key'], $period['start'], $locale);
            $warning = ['code' => 'NOT_ALIGNED', 'period' => ['key' => $period['key'], 'name' => $name, 'from' => $period['start']->format('Y-m-d'), 'to' => $period['end']->format('Y-m-d')], 'cutDate' => $from,
                'text' => $this->tr('conversation.actions.fiscal_year_set.warning.not_aligned', $locale, ['%period%' => $name, '%from%' => self::longDate($period['start']->format('Y-m-d'), $locale),
                    '%to%' => self::longDate($period['end']->format('Y-m-d'), $locale), '%cut%' => self::longDate($from, $locale)])];
        }

        return ['before' => ['startMonth' => $before, 'label' => ucfirst(self::month($before, $locale))], 'after' => ['startMonth' => $after, 'label' => ucfirst(self::month($after, $locale))],
            'exercise' => ['label' => GestionFiscalYearService::label($year, $after), 'from' => $from, 'to' => $to,
                'text' => $this->tr('conversation.actions.fiscal_year_set.exercise.text', $locale, ['%label%' => GestionFiscalYearService::label($year, $after), '%from%' => self::longDate($from, $locale), '%to%' => self::longDate($to, $locale)])],
            'aligned' => $aligned, 'warning' => $warning];
    }

    public function rows(array $preview, string $locale): array
    {
        return array_values(array_filter([
            $this->row('startMonth', $locale, $preview['after']['label'], 'text', ['before' => $preview['before']['label']]),
            $this->row('exercise', $locale, $preview['exercise']['text'], 'text'),
            $preview['warning'] === null ? null : $this->row('warning', $locale, $preview['warning']['text'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $before = $this->fiscal->startMonth();
        $this->fiscal->put(['startMonth' => (int) $answers['startMonth']]); // 422 du service ; journal gestion-reglages-exercice

        return ['result' => ['startMonth' => (int) $answers['startMonth']], 'undo' => ['before' => $before, 'after' => (int) $answers['startMonth']]];
    }

    public function blockers(array $undo): array
    {
        return $this->fiscal->startMonth() === (int) $undo['after'] ? [] : [['code' => 'FISCAL_YEAR_CHANGED', 'message' => 'Le mois de début d\'exercice a été modifié depuis.']];
    }

    public function undo(array $undo): array
    {
        $this->fiscal->put(['startMonth' => (int) $undo['before']]); // l'annulation passe par le même service : elle est au journal aussi

        return ['restored' => ['startMonth' => (int) $undo['before']]];
    }
}
