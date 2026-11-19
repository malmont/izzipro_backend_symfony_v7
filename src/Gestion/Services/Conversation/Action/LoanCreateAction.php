<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Gestion\Loan\GestionLoanService;
use App\Gestion\Loan\LoanScheduleCalculator;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `loan.create` (lot FIS-1, 19/11/2026) : saisir un prêt dans la devise du site (libellé, montant, taux annuel, durée, rythme, déblocage,
 * première échéance, type, frais de dossier, assurance fixe par échéance). Le différé, l'assurance en pourcentage, le prêteur et les notes
 * restent sur l'écran des prêts : le fil ne demande que ce qu'une offre de banque donne en une phrase, et ne demande jamais le nom du prêteur.
 * L'aperçu est l'échéancier CALCULÉ par le serveur (première échéance, dernière, coût total du crédit), avec les mêmes contrôles que la
 * route ; une fiche refusée par ces contrôles devient un « point à corriger » sur la question en cause. Annulation : retirer le prêt s'il
 * n'a pas changé depuis.
 */
class LoanCreateAction extends AbstractGestionAction
{
    /** Question du fil => champ de la fiche refusé par le service (même ordre que les questions) */
    private const FIELD_OF = ['label' => 'label', 'amount' => 'amount', 'ratePpm' => 'rate', 'durationMonths' => 'durationMonths', 'frequencyMonths' => 'frequencyMonths',
        'disbursedOn' => 'disbursedOn', 'firstDueOn' => 'firstDueOn', 'type' => 'type', 'fees' => 'fees', 'insuranceMode' => 'insuranceAmount'];

    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency, private readonly GestionLoanService $loans)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'loan.create';
    }

    public function build(array $answers, string $locale): array
    {
        $option = fn (string $group, string|int $value) => ['value' => $value, 'label' => $this->tr("conversation.actions.loan_create.options.$group.$value", $locale)];
        $issues = [];
        if ($this->complete($answers)) {
            try {
                $this->loans->preview($this->body($answers));
            } catch (GestionValidationException $e) {
                foreach ($e->fields as $field => $code) {
                    $issues[] = ['path' => self::FIELD_OF[$field] ?? $field, 'code' => $code];
                }
            }
        }

        return ['questions' => [
            $this->question('label', true, ['type' => 'text'], [], $answers['label'] ?? null, $locale),
            $this->question('amount', true, ['type' => 'money', 'currency' => $this->site()], [], $answers['amount'] ?? null, $locale),
            $this->question('rate', true, ['type' => 'number', 'min' => 0, 'max' => GestionLoanService::MAX_RATE_PPM / 10000, 'unit' => 'percent'], [], $answers['rate'] ?? null, $locale),
            $this->question('durationMonths', true, ['type' => 'number', 'min' => 1, 'max' => GestionLoanService::MAX_DURATION, 'integer' => true], [], $answers['durationMonths'] ?? null, $locale),
            $this->question('frequencyMonths', true, ['type' => 'choice'], array_map(fn (int $f) => $option('frequency', $f), GestionLoanService::FREQUENCIES), $answers['frequencyMonths'] ?? 1, $locale, [], !isset($answers['frequencyMonths'])),
            $this->question('disbursedOn', true, ['type' => 'date'], [], $answers['disbursedOn'] ?? null, $locale),
            $this->question('firstDueOn', true, ['type' => 'date'], [], $answers['firstDueOn'] ?? null, $locale),
            $this->question('type', true, ['type' => 'choice'], array_map(fn (string $t) => $option('type', $t), GestionLoanService::TYPES), $answers['type'] ?? LoanScheduleCalculator::TYPE_ANNUITY, $locale, [], !isset($answers['type'])),
            $this->question('fees', false, ['type' => 'money', 'currency' => $this->site()], [], $answers['fees'] ?? null, $locale),
            $this->question('insuranceAmount', false, ['type' => 'money', 'currency' => $this->site()], [], $answers['insuranceAmount'] ?? null, $locale),
        ], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'label':
                return $this->text($value, $index, 120);
            case 'amount':
                return $this->cents($value, $index);
            case 'rate':
                // un pourcentage annuel (« 4,5 ») : gardé en millionièmes pour le calcul, sans arrondi au-delà de 4 décimales
                return (is_int($value) || is_float($value)) && $value >= 0 && $value * 10000 <= GestionLoanService::MAX_RATE_PPM ? round((float) $value, 4) : $this->fail($index, 'OUT_OF_RANGE');
            case 'durationMonths':
                $months = self::whole($value);

                return $months !== null && $months >= 1 && $months <= GestionLoanService::MAX_DURATION ? $months : $this->fail($index, 'OUT_OF_RANGE');
            case 'frequencyMonths':
                return in_array(self::whole($value), GestionLoanService::FREQUENCIES, true) ? self::whole($value) : $this->fail($index, 'INVALID_VALUE');
            case 'disbursedOn':
                return self::day($value) ?? $this->fail($index, 'INVALID_DATE');
            case 'firstDueOn':
                $day = self::day($value) ?? $this->fail($index, 'INVALID_DATE');

                return isset($answers['disbursedOn']) && $day < $answers['disbursedOn'] ? $this->fail($index, 'BEFORE_START') : $day;
            case 'type':
                return is_string($value) && in_array($value, GestionLoanService::TYPES, true) ? $value : $this->fail($index, 'INVALID_VALUE');
            default: // fees, insuranceAmount : zéro admis (« pas de frais »)
                return $this->cents($value, $index, true);
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $p = $this->loans->preview($this->body($answers));
        $first = null;
        foreach ($p['schedule'] as $row) {
            if ($row['total'] > 0) {
                $first = $row;
                break;
            }
        }
        $last = $p['schedule'] !== [] ? end($p['schedule']) : null;

        return ['label' => $p['loan']['label'], 'amount' => $p['loan']['amount'], 'currency' => $p['loan']['currency'], 'rate' => round($p['loan']['ratePpm'] / 10000, 4),
            'durationMonths' => $p['loan']['durationMonths'], 'frequencyMonths' => $p['loan']['frequencyMonths'], 'disbursedOn' => $p['loan']['disbursedOn'], 'firstDueOn' => $p['loan']['firstDueOn'],
            'type' => $p['loan']['type'], 'fees' => $p['loan']['fees'], 'insuranceAmount' => $p['loan']['insuranceAmount'], 'instalments' => count($p['schedule']),
            'firstInstalment' => $first === null ? null : ['date' => $first['date'], 'total' => $first['total']], 'lastInstalment' => $last === null ? null : ['date' => $last['date'], 'total' => $last['total']],
            // le coût total du crédit (intérêts + assurance + frais) est dit à part du capital, qui n'est pas une charge (décision du 19/11)
            'totals' => $p['totals'],
            'overlap' => $this->hasLoanMovement() ? $this->tr('conversation.actions.loan_create.overlapNote', $locale) : null];
    }

    public function rows(array $preview, string $locale): array
    {
        $money = fn (string $param, ?int $value) => $value === null ? null : $this->row($param, $locale, $value, 'money', ['currency' => $preview['currency']]);

        return array_values(array_filter([
            $this->row('label', $locale, $preview['label'], 'text'),
            $money('amount', $preview['amount']),
            $this->row('rate', $locale, $preview['rate'], 'percent'),
            $this->row('durationMonths', $locale, $preview['durationMonths'], 'count'),
            $this->row('frequencyMonths', $locale, $this->tr('conversation.actions.loan_create.options.frequency.' . $preview['frequencyMonths'], $locale), 'text'),
            $this->row('disbursedOn', $locale, $preview['disbursedOn'], 'date'),
            $this->row('firstDueOn', $locale, $preview['firstDueOn'], 'date'),
            $this->row('type', $locale, $this->tr('conversation.actions.loan_create.options.type.' . $preview['type'], $locale), 'text'),
            $preview['fees'] > 0 ? $money('fees', $preview['fees']) : null,
            $preview['insuranceAmount'] > 0 ? $money('insuranceAmount', $preview['insuranceAmount']) : null,
            $preview['firstInstalment'] === null ? null : $money('instalment', $preview['firstInstalment']['total']),
            $money('creditCost', $preview['totals']['creditCost']),
            $preview['lastInstalment'] === null ? null : $this->row('lastDueOn', $locale, $preview['lastInstalment']['date'], 'date'),
            $preview['overlap'] === null ? null : $this->row('overlap', $locale, $preview['overlap'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $created = $this->loans->create($this->body($answers), $user); // mêmes contrôles que la route ; journal gestion-prets

        return ['result' => ['loanId' => $created['id']], 'undo' => ['id' => $created['id'], 'fiche' => $this->stored((int) $created['id'])]];
    }

    public function blockers(array $undo): array
    {
        $row = $this->stored((int) $undo['id']);
        if ($row === null) {
            return [['code' => 'LOAN_CHANGED', 'message' => 'Le prêt créé par cette action n\'existe plus.']];
        }

        return $row === $undo['fiche'] ? [] : [['code' => 'LOAN_CHANGED', 'message' => 'Le prêt a été modifié depuis sa création.']];
    }

    public function undo(array $undo): array
    {
        if ($this->stored((int) $undo['id']) === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Le prêt n\'existe plus.');
        }
        $this->loans->delete((int) $undo['id']);

        return ['removed' => ['loan' => (int) $undo['id']]];
    }

    /** Un nombre entier, écrit 24 ou 24.0 */
    private static function whole(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_float($value) && floor($value) === $value && abs($value) < 1e6 ? (int) $value : null);
    }

    private function complete(array $answers): bool
    {
        foreach (['label', 'amount', 'rate', 'durationMonths', 'disbursedOn', 'firstDueOn'] as $k) {
            if (!isset($answers[$k])) {
                return false;
            }
        }

        return true;
    }

    /** La fiche telle que la route la reçoit : taux en millionièmes, assurance fixe seulement si un montant est donné */
    private function body(array $answers): array
    {
        $insurance = (int) ($answers['insuranceAmount'] ?? 0);

        return ['label' => (string) ($answers['label'] ?? ''), 'amount' => (int) ($answers['amount'] ?? 0), 'currency' => $this->site(),
            'ratePpm' => (int) round((float) ($answers['rate'] ?? -1) * 10000), 'durationMonths' => (int) ($answers['durationMonths'] ?? 0),
            'frequencyMonths' => (int) ($answers['frequencyMonths'] ?? 1), 'disbursedOn' => $answers['disbursedOn'] ?? null, 'firstDueOn' => $answers['firstDueOn'] ?? null,
            'type' => $answers['type'] ?? LoanScheduleCalculator::TYPE_ANNUITY, 'fees' => (int) ($answers['fees'] ?? 0),
            'insuranceMode' => $insurance > 0 ? LoanScheduleCalculator::INSURANCE_FIXED : null, 'insuranceAmount' => $insurance];
    }

    /** Un mouvement prévu « prêt » saisi à la main : l'aperçu dit de le retirer pour ne pas compter les échéances deux fois (rien n'est supprimé) */
    private function hasLoanMovement(): bool
    {
        return $this->conn()->fetchOne("SELECT 1 FROM gestion_cash_movement WHERE category = 'loan' LIMIT 1") !== false;
    }

    /** @return array<string, mixed>|null ce que la fiche porte en base, pour constater un changement */
    private function stored(int $id): ?array
    {
        return $this->loans->find($id);
    }
}
