<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Gestion\Entity\GestionTrip;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionTripService;
use App\Gestion\Services\PeriodCalendar;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `trip.create` : créer un voyage d'achat (nom, lieu, dates, budget total, budget journalier, devise, taux, notes). À la création, le
 * service rattache seul les notes de frais et les commandes libres datées dans le voyage : l'aperçu les compte, et dit que l'annulation
 * les rendra libres. Deux voyages ne se chevauchent pas (`TRIP_OVERLAP`). Annulation : supprimer le voyage s'il n'a pas changé, qu'aucun
 * élément n'y a été rattaché à la main depuis et qu'aucun élément rattaché n'est dans une période fermée.
 */
class TripCreateAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly GestionTripService $trips, private readonly PeriodCalendar $calendar)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'trip.create';
    }

    public function build(array $answers, string $locale): array
    {
        $currency = $answers['currency'] ?? $this->site();
        $foreign = $currency !== $this->site();
        $issues = [];
        if (isset($answers['startDate'], $answers['endDate'])) {
            if ($answers['endDate'] < $answers['startDate']) {
                $issues[] = ['path' => 'endDate', 'code' => 'BEFORE_START'];
            } elseif (($overlap = $this->overlap((string) $answers['startDate'], (string) $answers['endDate'])) !== null) {
                $issues[] = ['path' => 'startDate', 'code' => 'TRIP_OVERLAP', 'tripId' => $overlap];
            }
        }
        $questions = [
            $this->question('name', true, ['type' => 'text'], [], $answers['name'] ?? null, $locale),
            $this->question('place', false, ['type' => 'text'], [], $answers['place'] ?? null, $locale),
            $this->question('startDate', true, ['type' => 'date'], [], $answers['startDate'] ?? null, $locale),
            $this->question('endDate', true, ['type' => 'date'], [], $answers['endDate'] ?? null, $locale),
            $this->question('budgetTotal', true, ['type' => 'money', 'currency' => $currency], [], $answers['budgetTotal'] ?? null, $locale),
            $this->question('budgetDaily', false, ['type' => 'money', 'currency' => $currency], [], $answers['budgetDaily'] ?? null, $locale),
            $this->question('currency', true, ['type' => 'choice'], array_map(fn (string $c) => ['value' => $c, 'label' => $c], SiteCurrencyService::SUPPORTED), $currency, $locale, [], !isset($answers['currency'])),
        ];
        if ($foreign) { // question posée seulement pour un voyage dans une autre devise que celle du site
            $questions[] = $this->question('exchangeRate', true, ['type' => 'number'], [], $answers['exchangeRate'] ?? null, $locale, ['%from%' => $currency, '%to%' => $this->site()]);
        }
        $questions[] = $this->question('notes', false, ['type' => 'text'], [], $answers['notes'] ?? null, $locale);

        return ['questions' => $questions, 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'name':
                return $this->text($value, $index);
            case 'place':
                return $this->text($value, $index);
            case 'startDate':
                return self::day($value) ?? $this->fail($index, 'INVALID_DATE');
            case 'endDate':
                $day = self::day($value) ?? $this->fail($index, 'INVALID_DATE');

                return isset($answers['startDate']) && $day < $answers['startDate'] ? $this->fail($index, 'BEFORE_START') : $day;
            case 'budgetTotal':
            case 'budgetDaily':
                return $this->cents($value, $index, true);
            case 'currency':
                return is_string($value) && in_array(strtoupper($value), SiteCurrencyService::SUPPORTED, true) ? strtoupper($value) : $this->fail($index, 'INVALID_CURRENCY');
            case 'exchangeRate':
                return (is_int($value) || is_float($value)) && $value > 0 ? (float) $value : $this->fail($index, 'MUST_BE_POSITIVE');
            default:
                return $this->text($value, $index, 2000);
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $v = $this->values($answers);
        $attaches = $this->free($v['startDate'], $v['endDate']);
        $days = (int) (new \DateTimeImmutable($v['startDate']))->diff(new \DateTimeImmutable($v['endDate']))->days + 1;

        return ['name' => $v['name'], 'place' => $v['place'], 'startDate' => $v['startDate'], 'endDate' => $v['endDate'], 'days' => $days,
            'budgetTotal' => $v['budgetTotal'], 'budgetDaily' => $v['budgetDaily'], 'currency' => $v['currency'] ?? $this->site(), 'exchangeRate' => $v['exchangeRate'], 'notes' => $v['notes'],
            // ce qui bouge dans les deux sens : rattaché à la création, rendu libre à l'annulation
            'attaches' => $attaches + ['text' => $this->tr('conversation.actions.trip_create.attaches.' . ($attaches['notes'] + $attaches['commandes'] > 0 ? 'some' : 'none'), $locale,
                ['%notes%' => (string) $attaches['notes'], '%commandes%' => (string) $attaches['commandes']])]];
    }

    public function rows(array $preview, string $locale): array
    {
        $money = ['currency' => $preview['currency']];

        return array_values(array_filter([
            $this->row('name', $locale, $preview['name'], 'text'),
            $preview['place'] === null ? null : $this->row('place', $locale, $preview['place'], 'text'),
            $this->row('startDate', $locale, $preview['startDate'], 'date'),
            $this->row('endDate', $locale, $preview['endDate'], 'date'),
            $this->row('budgetTotal', $locale, $preview['budgetTotal'], 'money', $money),
            $preview['budgetDaily'] === null ? null : $this->row('budgetDaily', $locale, $preview['budgetDaily'], 'money', $money),
            $this->row('currency', $locale, $preview['currency'], 'text'),
            $preview['exchangeRate'] === null ? null : $this->row('exchangeRate', $locale, $preview['exchangeRate'], 'number'),
            $preview['notes'] === null ? null : $this->row('notes', $locale, $preview['notes'], 'text'),
            $this->row('attaches', $locale, $preview['attaches']['text'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $trip = $this->trips->create($this->values($answers), $user); // 409 TRIP_OVERLAP, 422 du service ; rattachement automatique ; journal gestion-voyages

        return ['result' => ['tripId' => $trip->getId()], 'undo' => ['id' => $trip->getId()] + $this->snapshot($trip)];
    }

    public function blockers(array $undo): array
    {
        $trip = $this->em()->find(GestionTrip::class, (int) $undo['id']);
        if ($trip === null) {
            return [['code' => 'TRIP_CHANGED', 'message' => 'Le voyage créé par cette action n\'existe plus.']];
        }
        $reasons = [];
        if ($this->snapshot($trip) !== array_diff_key($undo, ['id' => true])) { // strict : le JSON du fil garde 2.0 en nombre à virgule
            $reasons[] = ['code' => 'TRIP_CHANGED', 'message' => 'Le voyage a été modifié depuis sa création.'];
        }
        $attached = array_merge(
            $this->conn()->fetchAllAssociative('SELECT trip_source, date FROM note_de_frais WHERE trip_id = ?', [(int) $undo['id']]),
            $this->conn()->fetchAllAssociative('SELECT trip_source, date FROM commande WHERE trip_id = ?', [(int) $undo['id']])
        );
        if (array_filter($attached, fn (array $r) => $r['trip_source'] === 'manual') !== []) {
            $reasons[] = ['code' => 'TRIP_USED', 'message' => 'Une note de frais ou une commande a été rattachée à ce voyage à la main depuis.'];
        }
        if (array_filter($attached, fn (array $r) => $this->closed((string) $r['date'])) !== []) {
            $reasons[] = ['code' => 'PERIOD_CLOSED', 'message' => 'Un élément rattaché à ce voyage est dans une période fermée : il garderait son rattachement.'];
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $trip = $this->em()->find(GestionTrip::class, (int) $undo['id']);
        if ($trip === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Le voyage n\'existe plus.');
        }
        $freed = ['notes' => (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM note_de_frais WHERE trip_id = ?', [(int) $undo['id']]),
            'commandes' => (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM commande WHERE trip_id = ?', [(int) $undo['id']])];
        $this->trips->delete($trip); // les éléments rattachés redeviennent libres

        return ['removed' => ['trip' => (int) $undo['id']], 'freed' => $freed];
    }

    /** Paramètres complets, sous la forme attendue par le service (devise du site = pas de devise ni de taux) */
    private function values(array $answers): array
    {
        $currency = $answers['currency'] ?? $this->site();
        $foreign = $currency !== $this->site();

        return ['name' => (string) $answers['name'], 'place' => $answers['place'] ?? null, 'startDate' => (string) $answers['startDate'], 'endDate' => (string) $answers['endDate'],
            'budgetTotal' => (int) $answers['budgetTotal'], 'budgetDaily' => $answers['budgetDaily'] ?? null, 'currency' => $foreign ? $currency : null,
            'exchangeRate' => $foreign ? ($answers['exchangeRate'] ?? null) : null, 'notes' => $answers['notes'] ?? null];
    }

    /** @return array<string, mixed> ce que le voyage porte, pour constater un changement */
    private function snapshot(GestionTrip $t): array
    {
        return ['name' => $t->getName(), 'place' => $t->getPlace(), 'startDate' => $t->getStartDate()?->format('Y-m-d'), 'endDate' => $t->getEndDate()?->format('Y-m-d'),
            'budgetTotal' => $t->getBudgetTotalCents(), 'budgetDaily' => $t->getBudgetDailyCents(), 'currency' => $t->getCurrency(), 'exchangeRate' => $t->getExchangeRate(), 'notes' => $t->getNotes()];
    }

    /** Identifiant d'un voyage qui chevauche ces dates (même règle que le service), ou null */
    private function overlap(string $start, string $end): ?int
    {
        $id = $this->conn()->fetchOne('SELECT id FROM gestion_trip WHERE start_date <= :e AND end_date >= :s ORDER BY start_date LIMIT 1', ['s' => $start, 'e' => $end]);

        return $id === false ? null : (int) $id;
    }

    /**
     * Notes de frais et commandes libres datées dans le voyage, hors périodes fermées : celles que le service rattachera seul à la création
     * (même règle que `GestionTripService::syncAuto`, en lecture seule).
     *
     * @return array{notes: int, commandes: int}
     */
    private function free(string $start, string $end): array
    {
        $notes = $this->conn()->fetchFirstColumn('SELECT date FROM note_de_frais WHERE trip_id IS NULL AND trip_source IS NULL AND date BETWEEN :s AND :e', ['s' => $start, 'e' => $end]);
        $commandes = $this->conn()->fetchFirstColumn("SELECT date FROM commande WHERE kind <> 'reprise' AND trip_id IS NULL AND trip_source IS NULL AND CAST(date AS date) BETWEEN :s AND :e", ['s' => $start, 'e' => $end]);
        $open = fn (array $dates): int => count(array_filter($dates, fn ($d) => !$this->closed((string) $d)));

        return ['notes' => $open($notes), 'commandes' => $open($commandes)];
    }

    private function closed(string $date): bool
    {
        $period = $this->calendar->findByDate(new \DateTimeImmutable(substr($date, 0, 10)));

        return $period !== null && $this->calendar->isClosed($period);
    }
}
