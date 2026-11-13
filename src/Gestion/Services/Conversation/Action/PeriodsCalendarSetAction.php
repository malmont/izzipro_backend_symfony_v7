<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Entity\Entreprise;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `periods.calendar.set` : choisir le découpage des périodes de gestion (trimestres saisonniers, semestres, mois). Les périodes déjà
 * créées ne bougent pas, et la période qui suit celle en cours est déjà créée : le nouveau découpage commence le lendemain de la fin de la
 * dernière période existante, ce que l'aperçu dit en clair. Écrit par le chemin du réglage existant (fiche Entreprise, registre
 * `gestion.periods`, journal `entreprise`). Annulation : remettre la valeur d'avant, si le réglage n'a pas changé depuis et qu'aucune
 * période n'a été créée avec le nouveau découpage.
 */
class PeriodsCalendarSetAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly UpdateEntrepriseUseCase $update, private readonly PeriodCalendar $calendar, private readonly RequestStack $requests)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'periods.calendar.set';
    }

    public function build(array $answers, string $locale): array
    {
        $stored = $this->entreprise()?->getPurchasePeriodFrequency();
        $effective = $this->calendar->frequency();
        $options = array_map(fn (string $f) => ['value' => $f, 'label' => $this->label($f, $locale), 'current' => $f === $effective], PeriodCalendar::FREQUENCIES);
        $answer = $answers['frequency'] ?? null;
        // Confirmer le découpage par défaut (rien d'enregistré) est une vraie écriture ; rechoisir la valeur déjà enregistrée ne l'est pas
        $issues = $answer !== null && $answer === $stored ? [['path' => 'frequency', 'code' => 'UNCHANGED']] : [];

        return ['questions' => [$this->question('frequency', true, ['type' => 'choice'], $options, $answer, $locale)], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && in_array($value, PeriodCalendar::FREQUENCIES, true) ? $value : $this->fail($index, 'INVALID_FREQUENCY');
    }

    public function preview(array $answers, string $locale): array
    {
        $before = $this->calendar->frequency();
        $after = (string) $answers['frequency'];
        $appliesFrom = $this->appliesFrom();
        $text = $before === $after
            ? $this->tr('conversation.actions.periods_calendar_set.applies.same', $locale)
            : $this->tr('conversation.actions.periods_calendar_set.applies.text', $locale, ['%date%' => self::longDate($appliesFrom, $locale)]);

        return ['before' => ['frequency' => $before, 'label' => $this->label($before, $locale), 'confirmed' => $this->entreprise()?->getPurchasePeriodFrequency() !== null],
            'after' => ['frequency' => $after, 'label' => $this->label($after, $locale)], 'appliesFrom' => $before === $after ? null : $appliesFrom, 'text' => $text];
    }

    public function rows(array $preview, string $locale): array
    {
        return [$this->row('frequency', $locale, $preview['after']['label'], 'text', ['before' => $preview['before']['label']]), $this->row('applies', $locale, $preview['text'], 'text')];
    }

    public function execute(array $answers, ?string $user): array
    {
        $entreprise = $this->entreprise();
        if ($entreprise === null) {
            throw new GestionConflictException('ENTREPRISE_NOT_FOUND', 'Fiche entreprise du site introuvable.', 404);
        }
        $undo = ['entrepriseId' => (int) $entreprise->getId(), 'before' => $entreprise->getPurchasePeriodFrequency(), 'beforeEffective' => $this->calendar->frequency(), 'after' => (string) $answers['frequency'], 'periods' => $this->periods()];
        $this->write($undo['entrepriseId'], $undo['after']);

        return ['result' => ['frequency' => $undo['after']], 'undo' => $undo];
    }

    public function blockers(array $undo): array
    {
        $reasons = [];
        if ($this->entreprise()?->getPurchasePeriodFrequency() !== $undo['after']) {
            $reasons[] = ['code' => 'CALENDAR_CHANGED', 'message' => 'Le découpage des périodes a été modifié depuis.'];
        }
        if ($undo['beforeEffective'] !== $undo['after'] && $this->periods() > (int) $undo['periods']) {
            $reasons[] = ['code' => 'PERIODS_CREATED', 'message' => 'Une période a déjà été créée avec le nouveau découpage : le remettre laisserait une période au mauvais format.'];
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $this->write((int) $undo['entrepriseId'], $undo['before']); // même chemin que la pose : l'annulation est au journal aussi

        return ['restored' => ['frequency' => $undo['before']]];
    }

    private function write(int $entrepriseId, ?string $frequency): void
    {
        $host = $this->requests->getCurrentRequest()?->getSchemeAndHttpHost() ?? '';
        $this->update->execute($entrepriseId, ['purchasePeriodFrequency' => $frequency], $host, 'fr');
    }

    private function label(string $frequency, string $locale): string
    {
        return $this->tr("conversation.actions.periods_calendar_set.options.$frequency", $locale);
    }

    private function entreprise(): ?Entreprise
    {
        return $this->em()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
    }

    private function periods(): int
    {
        return (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM collections WHERE kind = 'period'");
    }

    /** Premier jour du nouveau découpage : le lendemain de la fin de la dernière période existante, sinon aujourd'hui (lecture seule) */
    private function appliesFrom(): string
    {
        $last = $this->conn()->fetchOne("SELECT MAX(end_date_collection) FROM collections WHERE kind = 'period'");

        return $last ? (new \DateTimeImmutable(substr((string) $last, 0, 10)))->modify('+1 day')->format('Y-m-d') : $this->calendar->today()->format('Y-m-d');
    }
}
