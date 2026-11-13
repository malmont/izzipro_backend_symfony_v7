<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Dto\NoteDeFraisInputDTO;
use App\Entity\NoteDeFrais;
use App\Entity\TypeNoteDeFrais;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodCalendar;
use App\Gestion\Services\PeriodGuard;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\NoteDeFraisService\NoteDeFraisService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `expense.create` : saisir une note de frais (montant, date, type, description), rangée dans la période de sa date. Annulation : la
 * retirer si elle n'a pas changé et que sa période est ouverte.
 */
class ExpenseCreateAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly NoteDeFraisService $notes, private readonly PeriodGuard $guard, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'expense.create';
    }

    public function build(array $answers, string $locale): array
    {
        $types = array_map(fn (TypeNoteDeFrais $t) => ['value' => (int) $t->getId(), 'label' => (string) $t->getName()], $this->em()->getRepository(TypeNoteDeFrais::class)->findBy([], ['name' => 'ASC']));
        $issues = [];
        $date = self::day($answers['date'] ?? null);
        $period = $date !== null ? $this->calendar->findByDate(new \DateTimeImmutable($date)) : null;
        if ($period !== null && $this->calendar->isClosed($period)) {
            $issues[] = ['path' => 'date', 'code' => 'PERIOD_CLOSED'];
        }

        return ['questions' => [
            $this->question('amount', true, ['type' => 'money', 'currency' => $this->site()], [], $answers['amount'] ?? null, $locale),
            $this->question('date', true, ['type' => 'date'], [], $date, $locale),
            $this->question('typeNoteDeFraisId', true, ['type' => 'choice'], $types, $answers['typeNoteDeFraisId'] ?? null, $locale),
            $this->question('description', true, ['type' => 'text'], [], $answers['description'] ?? null, $locale),
        ], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'amount':
                return $this->cents($value, $index);
            case 'date':
                $day = self::day($value) ?? $this->fail($index, 'INVALID_DATE');
                $period = $this->calendar->findByDate(new \DateTimeImmutable($day));

                return $period !== null && $this->calendar->isClosed($period) ? $this->fail($index, 'PERIOD_CLOSED') : $day;
            case 'typeNoteDeFraisId':
                return is_int($value) && $this->em()->find(TypeNoteDeFrais::class, $value) !== null ? $value : $this->fail($index, 'TYPE_NOTE_DE_FRAIS_NOT_FOUND');
            default:
                return $this->text($value, $index);
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $date = new \DateTimeImmutable((string) $answers['date']);
        $period = $this->calendar->findByDate($date);
        $type = $this->em()->find(TypeNoteDeFrais::class, (int) $answers['typeNoteDeFraisId']);

        return ['amount' => (int) $answers['amount'], 'currency' => $this->site(), 'date' => $date->format('Y-m-d'), 'description' => (string) $answers['description'],
            'type' => ['id' => $type?->getId(), 'name' => $type?->getName()],
            'period' => $period === null ? null : ['id' => $period->getId(), 'name' => PeriodCalendar::displayName($period, $locale)]];
    }

    public function rows(array $preview, string $locale): array
    {
        return array_values(array_filter([
            $this->row('amount', $locale, $preview['amount'], 'money', ['currency' => $preview['currency']]),
            $this->row('date', $locale, $preview['date'], 'date'),
            $this->row('typeNoteDeFraisId', $locale, $preview['type']['name'], 'text'),
            $this->row('description', $locale, $preview['description'], 'text'),
            $preview['period'] === null ? null : $this->row('period', $locale, $preview['period']['name'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $date = new \DateTimeImmutable((string) $answers['date']);
        $period = $this->guard->assertDateWritable($date); // 409 PERIOD_CLOSED ; crée la période de la date si elle manque
        if ($period === null) {
            throw new GestionValidationException(['date' => 'OUT_OF_CALENDAR'], 'Aucune période du calendrier ne couvre cette date');
        }
        $note = $this->notes->createNoteDeFrais($period, new NoteDeFraisInputDTO((string) $answers['description'], (int) $answers['amount'], $date->format('Y-m-d'), (int) $answers['typeNoteDeFraisId']));

        return ['result' => ['noteDeFraisId' => $note->getId(), 'periodId' => $period->getId()],
            'undo' => ['id' => $note->getId(), 'montant' => $note->getMontant(), 'date' => $note->getDate()?->format('Y-m-d'), 'description' => $note->getDescription(), 'typeNoteDeFraisId' => $note->getTypeNoteDeFrais()?->getId()]];
    }

    public function blockers(array $undo): array
    {
        $note = $this->em()->find(NoteDeFrais::class, (int) $undo['id']);
        if ($note === null) {
            return [['code' => 'EXPENSE_CHANGED', 'message' => 'La note de frais créée par cette action n\'existe plus.']];
        }
        $reasons = [];
        if ($note->getMontant() !== $undo['montant'] || $note->getDate()?->format('Y-m-d') !== $undo['date'] || $note->getDescription() !== $undo['description'] || $note->getTypeNoteDeFrais()?->getId() !== $undo['typeNoteDeFraisId']) {
            $reasons[] = ['code' => 'EXPENSE_CHANGED', 'message' => 'La note de frais a été modifiée depuis sa création.'];
        }
        if ($note->getCollection() !== null && $this->calendar->isClosed($note->getCollection())) {
            $reasons[] = ['code' => 'PERIOD_CLOSED', 'message' => 'La période de la note de frais est fermée.'];
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $note = $this->em()->find(NoteDeFrais::class, (int) $undo['id']);
        if ($note === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'La note de frais n\'existe plus.');
        }
        $this->notes->deleteNoteDeFrais($note);

        return ['removed' => ['expense' => (int) $undo['id']]];
    }
}
