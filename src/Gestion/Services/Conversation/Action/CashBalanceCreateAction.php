<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `cash.balance.create` : saisir un solde de trésorerie daté (date, montant, note). Le montant peut être négatif. Un solde déjà saisi à la
 * même date est REMPLACÉ par le service : l'aperçu le dit (`replaces`), l'empreinte le couvre. Annulation : retirer le solde créé, ou
 * remettre le montant et la note d'avant s'il en a remplacé un ; refusée si le solde a changé depuis.
 */
class CashBalanceCreateAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly GestionCashService $cash, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'cash.balance.create';
    }

    public function build(array $answers, string $locale): array
    {
        $today = $this->calendar->today()->format('Y-m-d');

        return ['questions' => [
            $this->question('date', true, ['type' => 'date', 'max' => $today], [], $answers['date'] ?? $today, $locale, [], !isset($answers['date'])),
            $this->question('amount', true, ['type' => 'money', 'currency' => $this->site(), 'signed' => true], [], $answers['amount'] ?? null, $locale),
            $this->question('note', false, ['type' => 'text'], [], $answers['note'] ?? null, $locale),
        ], 'issues' => []];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'date':
                $day = self::day($value) ?? $this->fail($index, 'INVALID_DATE');

                return $day > $this->calendar->today()->format('Y-m-d') ? $this->fail($index, 'DATE_IN_FUTURE') : $day;
            case 'amount':
                return is_int($value) ? $value : $this->fail($index, 'INVALID_AMOUNT'); // négatif admis : un découvert
            default:
                return $this->text($value, $index, 500);
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $date = $this->date($answers);
        $existing = $this->at($date);

        return ['date' => $date, 'amount' => (int) $answers['amount'], 'currency' => $this->site(), 'note' => $answers['note'] ?? null,
            'replaces' => $existing === null ? null : ['id' => (int) $existing['id'], 'amount' => (int) $existing['amount_cents'], 'note' => $existing['note']]];
    }

    public function execute(array $answers, ?string $user): array
    {
        $date = $this->date($answers);
        $existing = $this->at($date);
        $saved = $this->cash->saveBalance(['date' => $date, 'amount' => (int) $answers['amount'], 'note' => $answers['note'] ?? null], $user); // 422 DATE_IN_FUTURE ; journal gestion-tresorerie-soldes

        return ['result' => ['balanceId' => $saved['id'], 'replaced' => $saved['replaced']],
            'undo' => ['id' => $saved['id'], 'date' => $saved['date'], 'amount' => $saved['amount'], 'note' => $saved['note'],
                'before' => $existing === null ? null : ['amount' => (int) $existing['amount_cents'], 'note' => $existing['note']]]];
    }

    public function blockers(array $undo): array
    {
        $row = $this->conn()->fetchAssociative('SELECT balance_date, amount_cents, note FROM gestion_cash_balance WHERE id = ?', [(int) $undo['id']]);
        if ($row === false) {
            return [['code' => 'BALANCE_CHANGED', 'message' => 'Le solde saisi par cette action n\'existe plus.']];
        }
        if (substr((string) $row['balance_date'], 0, 10) !== $undo['date'] || (int) $row['amount_cents'] !== (int) $undo['amount'] || $row['note'] !== $undo['note']) {
            return [['code' => 'BALANCE_CHANGED', 'message' => 'Le solde a été modifié depuis sa saisie.']];
        }

        return [];
    }

    public function undo(array $undo): array
    {
        if ($this->conn()->fetchOne('SELECT 1 FROM gestion_cash_balance WHERE id = ?', [(int) $undo['id']]) === false) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Le solde n\'existe plus.');
        }
        if ($undo['before'] === null) {
            $this->cash->deleteBalance((int) $undo['id']);

            return ['removed' => ['balance' => (int) $undo['id']]];
        }
        $this->cash->updateBalance((int) $undo['id'], ['amount' => (int) $undo['before']['amount'], 'note' => $undo['before']['note']]);

        return ['restored' => ['balance' => (int) $undo['id']]];
    }

    /** Date répondue, sinon celle proposée : aujourd'hui (jour du magasin) */
    private function date(array $answers): string
    {
        return (string) ($answers['date'] ?? $this->calendar->today()->format('Y-m-d'));
    }

    /** @return array<string, mixed>|null le solde déjà saisi à cette date (lecture seule) */
    private function at(string $date): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT id, amount_cents, note FROM gestion_cash_balance WHERE balance_date = ?', [$date]);

        return $row === false ? null : $row;
    }
}
