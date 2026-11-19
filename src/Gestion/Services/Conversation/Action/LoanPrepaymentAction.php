<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Gestion\Loan\GestionLoanService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `loan.prepayment` (lot FIS-1, 19/11/2026) : ajouter un remboursement anticipé à un prêt (prêt, date, montant, frais). L'aperçu est calculé
 * par le serveur sans rien écrire : l'échéance qui suit le remboursement, avant et après (la date de fin ne change pas : ce sont les
 * échéances qui baissent), le coût total du crédit avant et après, et les charges économisées. Annulation : retirer CE remboursement, si la fiche du prêt n'a pas changé depuis.
 */
class LoanPrepaymentAction extends AbstractGestionAction
{
    public const LISTED = 50;

    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency, private readonly GestionLoanService $loans)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'loan.prepayment';
    }

    public function build(array $answers, string $locale): array
    {
        // Prêts proposés : par leur libellé (jamais le prêteur), les plus récents d'abord
        $options = array_map(fn (array $l) => ['value' => $l['id'], 'label' => $l['label'], 'remaining' => $l['remainingToday'], 'currency' => $l['currency']],
            array_slice($this->loans->list(), 0, self::LISTED));
        $issues = [];
        if (isset($answers['loanId']) && $this->loans->find((int) $answers['loanId']) === null) {
            $issues[] = ['path' => 'loanId', 'code' => 'LOAN_NOT_FOUND'];
        } elseif (isset($answers['loanId'], $answers['date'], $answers['amount'])) {
            try {
                $this->simulate($answers);
            } catch (GestionValidationException $e) {
                foreach ($e->fields as $field => $code) {
                    $issues[] = ['path' => $field === 'prepayments' ? 'amount' : $field, 'code' => $code];
                }
            }
        }

        return ['questions' => [
            $this->question('loanId', true, ['type' => 'choice'], $options, $answers['loanId'] ?? null, $locale),
            $this->question('date', true, ['type' => 'date'], [], $answers['date'] ?? null, $locale),
            $this->question('amount', true, ['type' => 'money', 'currency' => $this->currencyOf($answers)], [], $answers['amount'] ?? null, $locale),
            $this->question('fees', false, ['type' => 'money', 'currency' => $this->currencyOf($answers)], [], $answers['fees'] ?? null, $locale),
        ], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($question['id']) {
            'loanId' => is_int($value) && $this->loans->find($value) !== null ? $value : $this->fail($index, 'LOAN_NOT_FOUND'),
            'date' => self::day($value) ?? $this->fail($index, 'INVALID_DATE'),
            'amount' => $this->cents($value, $index),
            default => $this->cents($value, $index, true),
        };
    }

    public function preview(array $answers, string $locale): array
    {
        $before = $this->loans->get((int) $answers['loanId']);
        $after = $this->simulate($answers);
        $following = static function (array $schedule) use ($answers): ?int {
            foreach ($schedule as $row) {
                if ($row['date'] > $answers['date'] && $row['total'] > 0) {
                    return $row['total'];
                }
            }

            return null; // remboursé en entier : plus d'échéance après
        };

        return ['loanId' => (int) $answers['loanId'], 'label' => $before['label'], 'currency' => $before['currency'], 'date' => $answers['date'], 'amount' => (int) $answers['amount'],
            'fees' => (int) ($answers['fees'] ?? 0), 'remainingBefore' => $before['remainingToday'], 'instalmentBefore' => $following($before['schedule']), 'instalmentAfter' => $following($after['schedule']),
            'creditCostBefore' => $before['totals']['creditCost'], 'creditCostAfter' => $after['totals']['creditCost'],
            // ce que le remboursement fait économiser en charges (intérêts et assurance, moins ses frais) : calculé, jamais estimé par l'IA
            'saved' => $before['totals']['creditCost'] - $after['totals']['creditCost']];
    }

    public function rows(array $preview, string $locale): array
    {
        $money = fn (string $param, int $value) => $this->row($param, $locale, $value, 'money', ['currency' => $preview['currency']]);

        return array_values(array_filter([
            $this->row('loanId', $locale, $preview['label'], 'text'),
            $this->row('date', $locale, $preview['date'], 'date'),
            $money('amount', $preview['amount']),
            $preview['fees'] > 0 ? $money('fees', $preview['fees']) : null,
            $money('remainingBefore', $preview['remainingBefore']),
            $preview['instalmentBefore'] === null ? null : $money('instalmentAfter', (int) $preview['instalmentAfter']) + ['before' => $preview['instalmentBefore']],
            $money('creditCostAfter', $preview['creditCostAfter']) + ['before' => $preview['creditCostBefore']],
            $money('saved', $preview['saved']),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $id = (int) $answers['loanId'];
        $entry = ['date' => (string) $answers['date'], 'amount' => (int) $answers['amount'], 'fees' => (int) ($answers['fees'] ?? 0)];
        $this->loans->addPrepayment($id, $entry); // mêmes contrôles que la route ; journal gestion-prets

        return ['result' => ['loanId' => $id], 'undo' => ['id' => $id, 'entry' => $entry, 'fiche' => $this->loans->find($id)]];
    }

    public function blockers(array $undo): array
    {
        $fiche = $this->loans->find((int) $undo['id']);
        if ($fiche === null) {
            return [['code' => 'LOAN_CHANGED', 'message' => 'Le prêt n\'existe plus.']];
        }

        return $fiche === $undo['fiche'] ? [] : [['code' => 'LOAN_CHANGED', 'message' => 'Le prêt a été modifié depuis ce remboursement.']];
    }

    public function undo(array $undo): array
    {
        $fiche = $this->loans->find((int) $undo['id']);
        $index = $fiche === null ? false : array_search($undo['entry'], $fiche['prepayments'], true);
        if ($index === false) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Le remboursement anticipé n\'existe plus.');
        }
        $this->loans->removePrepayment((int) $undo['id'], (int) $index);

        return ['removed' => ['loan' => (int) $undo['id'], 'prepayment' => $undo['entry']]];
    }

    /** L'échéancier du prêt avec ce remboursement de plus, SANS écriture (mêmes contrôles que la route) */
    private function simulate(array $answers): array
    {
        $fiche = $this->loans->find((int) $answers['loanId']) ?? throw new GestionConflictException('LOAN_NOT_FOUND', 'Prêt introuvable.', 404);
        $fiche['prepayments'][] = ['date' => (string) $answers['date'], 'amount' => (int) $answers['amount'], 'fees' => (int) ($answers['fees'] ?? 0)];

        return $this->loans->preview($fiche);
    }

    /** Devise du prêt choisi, sinon celle du site */
    private function currencyOf(array $answers): string
    {
        $fiche = isset($answers['loanId']) ? $this->loans->find((int) $answers['loanId']) : null;

        return $fiche['currency'] ?? $this->site();
    }
}
