<?php

namespace App\Abo\Services;

use App\Entity\Subscription;
use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le travail de l'intervenant (lot ABO-3b, 20/11/2026, `docs/boutique.md` § « Intervenant et fil de l'abonné ») : SES dates seulement
 * (`AboStaffService::EFFECTIVE`), du jour ou de la semaine, et les mêmes constats qu'ABO-3a (« fait », « absent », séance entière, photo).
 * Ce qu'il voit de l'abonné : le PRÉNOM, et l'adresse de l'intervention pour une prestation chez le client ; jamais le nom, le courriel, le
 * téléphone, le numéro d'abonnement, le solde, le prix, l'historique, les notes de l'équipe, l'avis ni la contestation. La date d'un autre
 * intervenant : 404, comme une date qui n'existe pas. Fenêtre : 30 jours en arrière, 14 jours devant.
 */
class AboStaffWorkService
{
    public const BACK_DAYS = 30;
    public const AHEAD_DAYS = 14;
    private const MAX = 300;
    /** @var array<string, string> */
    private array $names = [];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StoreClock $clock,
        private readonly AboDeliveryService $delivery,
        private readonly BenefitService $benefits,
    ) {
    }

    /**
     * « À faire aujourd'hui » de l'intervenant : ses séances (créneaux dont il est l'intervenant) et ses rendez-vous.
     *
     * @return array{date: string, timezone: string, counts: array{toMark: int, done: int, missed: int}, sessions: list<array<string, mixed>>, appointments: list<array<string, mixed>>}
     * @throws AboException 422 DATE_INVALID
     */
    public function day(User $staff, ?string $date, string $locale): array
    {
        $tz = $this->clock->timezone();
        $day = $date === null || $date === '' ? (new \DateTimeImmutable('now', $tz))->format('Y-m-d') : self::day0($date, $tz);
        self::assertWindow(new \DateTimeImmutable($day, $tz), $tz, self::AHEAD_DAYS, 'date');
        [$start, $end] = $this->clock->range($day, $day);
        $items = $this->items((int) $staff->getId(), $start, $end, $locale);
        $slots = $this->conn()->fetchAllAssociative('SELECT sl.id, sl.benefit_id, sl.starts_at, sl.ends_at, sl.location, sl.capacity FROM benefit_slot sl JOIN benefit b ON b.id = sl.benefit_id
            WHERE sl.status = \'open\' AND sl.starts_at >= ? AND sl.starts_at < ? AND COALESCE(sl.staff_user_id, b.default_staff_user_id) = ? ORDER BY sl.starts_at, sl.id LIMIT ' . self::MAX,
            [self::utc($start), self::utc($end), (int) $staff->getId()]);
        $sessions = [];
        foreach ($slots as $s) {
            $sessions[(int) $s['id']] = ['slotId' => (int) $s['id'], 'benefitId' => (int) $s['benefit_id'], 'name' => $this->name((int) $s['benefit_id'], $locale),
                'startsAt' => $this->at($s['starts_at']), 'endsAt' => $this->at($s['ends_at']), 'location' => $s['location'], 'capacity' => (int) $s['capacity'],
                'booked' => 0, 'done' => 0, 'missed' => 0, 'toMark' => 0, 'appointments' => []];
        }
        $counts = ['toMark' => 0, 'done' => 0, 'missed' => 0];
        $free = [];
        foreach ($items as $item) {
            $key = match ($item['status']) { 'scheduled' => 'toMark', 'done' => 'done', 'missed' => 'missed', default => null };
            if ($key !== null) {
                ++$counts[$key];
            }
            $slotId = $item['slotId'];
            if ($slotId !== null && isset($sessions[$slotId])) {
                $sessions[$slotId]['appointments'][] = $item;
                ++$sessions[$slotId]['booked'];
                if ($key !== null) {
                    ++$sessions[$slotId][$key];
                }
            } else {
                $free[] = $item;
            }
        }

        return ['date' => $day, 'timezone' => $tz->getName(), 'counts' => $counts, 'sessions' => array_values($sessions), 'appointments' => $free];
    }

    /**
     * Ses dates de 14 jours au plus à partir d'un jour du magasin (aujourd'hui par défaut), dans l'ordre.
     *
     * @return array{from: string, to: string, timezone: string, appointments: list<array<string, mixed>>}
     * @throws AboException 422 DATE_INVALID
     */
    public function week(User $staff, ?string $from, string $locale): array
    {
        $tz = $this->clock->timezone();
        $today = new \DateTimeImmutable('today', $tz);
        $first = $from === null || $from === '' ? $today : new \DateTimeImmutable(self::day0($from, $tz), $tz);
        // relecture neuve : les 14 jours tiennent dans la fenêtre (jamais plus de 14 jours devant)
        self::assertWindow($first, $tz, self::AHEAD_DAYS - 13, 'from');
        $last = $first->modify('+13 days');
        [$start, $end] = $this->clock->range($first->format('Y-m-d'), $last->format('Y-m-d'));

        return ['from' => $first->format('Y-m-d'), 'to' => $last->format('Y-m-d'), 'timezone' => $tz->getName(), 'appointments' => $this->items((int) $staff->getId(), $start, $end, $locale)];
    }

    /**
     * « Fait » (note, photo) sur une de SES dates, par les règles d'ABO-3a (le jour même ou après, un courriel par droit, photo réencodée).
     *
     * @return array{occurrence: array<string, mixed>, changed: bool}
     */
    public function markDone(User $staff, int $occurrenceId, ?string $note, ?UploadedFile $photo, string $host, string $locale): array
    {
        $r = $this->delivery->markDone($this->owned($staff, $occurrenceId), $occurrenceId, $note, $photo, self::by($staff), $host, $locale, (int) $staff->getId());

        return ['occurrence' => $this->one((int) $staff->getId(), $occurrenceId, $locale), 'changed' => $r['changed']];
    }

    /** @return array{occurrence: array<string, mixed>, changed: bool} */
    public function markMissed(User $staff, int $occurrenceId, ?string $note, string $locale): array
    {
        $r = $this->delivery->markMissed($this->owned($staff, $occurrenceId), $occurrenceId, $note, self::by($staff), $locale, (int) $staff->getId());

        return ['occurrence' => $this->one((int) $staff->getId(), $occurrenceId, $locale), 'changed' => $r['changed']];
    }

    /**
     * Une de SES séances entière : « fait » pour ses inscrits, sauf les absents désignés par leur numéro de DATE (il ne voit pas les numéros
     * d'abonnement).
     *
     * @return array{slotId: int, done: int, missed: int, unchanged: int, failed: int}
     * @throws AboException 404 SLOT_NOT_FOUND, 422 ABSENT_INVALID | ABSENT_NOT_ON_SLOT
     */
    public function markSession(User $staff, int $slotId, mixed $absentOccurrences, ?string $note, string $host, string $locale): array
    {
        $staffId = (int) $staff->getId();
        // relecture neuve : une séance de la fenêtre seulement (jamais une séance oubliée depuis des mois, qui enverrait un courriel à chacun)
        $mine = $this->conn()->fetchOne('SELECT 1 FROM benefit_slot sl JOIN benefit b ON b.id = sl.benefit_id WHERE sl.id = ? AND COALESCE(sl.staff_user_id, b.default_staff_user_id) = ? AND sl.starts_at >= ?',
            [$slotId, $staffId, self::utc((new \DateTimeImmutable())->modify('-' . self::BACK_DAYS . ' days'))]);
        if ($mine === false) {
            throw new AboException(404, 'SLOT_NOT_FOUND', 'Créneau introuvable.', 'slotId');
        }
        $absentOccurrences ??= [];
        if (!is_array($absentOccurrences) || count($absentOccurrences) > self::MAX || array_filter($absentOccurrences, fn ($v) => !is_int($v) && !(is_string($v) && ctype_digit($v))) !== []) {
            throw new AboException(422, 'ABSENT_INVALID', 'Les absents sont une liste de numéros de date.', 'absentOccurrenceIds');
        }
        $absentOccurrences = array_values(array_unique(array_map('intval', $absentOccurrences)));
        $rows = $this->conn()->fetchAllAssociative('SELECT o.id, o.subscription_id FROM benefit_occurrence o JOIN benefit b ON b.id = o.benefit_id LEFT JOIN benefit_slot sl ON sl.id = o.slot_id
            WHERE o.slot_id = ? AND ' . AboStaffService::EFFECTIVE . ' = ? AND o.status IN (\'scheduled\', \'done\', \'missed\')', [$slotId, $staffId]);
        $bySubscription = [];
        foreach ($rows as $r) {
            $bySubscription[(int) $r['id']] = (int) $r['subscription_id'];
        }
        if (($stranger = array_diff($absentOccurrences, array_keys($bySubscription))) !== []) {
            throw new AboException(422, 'ABSENT_NOT_ON_SLOT', sprintf('Date(s) %s : pas sur cette séance.', implode(', ', $stranger)), 'absentOccurrenceIds');
        }
        $absentSubscriptions = array_values(array_map(fn (int $occ) => $bySubscription[$occ], $absentOccurrences));
        $r = $this->delivery->markSessionDone($slotId, $absentSubscriptions, $note, self::by($staff), $host, $locale, $staffId);

        return ['slotId' => $slotId, 'done' => $r['done'], 'missed' => $r['missed'], 'unchanged' => $r['unchanged'], 'failed' => count($r['failed'])];
    }

    /** @return array{path: string, mime: string, sha256: ?string} */
    public function proof(User $staff, int $occurrenceId): array
    {
        return $this->delivery->proofFile($this->owned($staff, $occurrenceId), $occurrenceId);
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    /** L'abonnement d'une de SES dates (planifiée, faite ou absente, dans la fenêtre) ; 404 sinon, sans dire si la date existe @throws AboException */
    private function owned(User $staff, int $occurrenceId): Subscription
    {
        $sid = $this->conn()->fetchOne('SELECT o.subscription_id FROM benefit_occurrence o JOIN benefit b ON b.id = o.benefit_id LEFT JOIN benefit_slot sl ON sl.id = o.slot_id
            WHERE o.id = ? AND ' . AboStaffService::EFFECTIVE . ' = ? AND o.status IN (\'scheduled\', \'done\', \'missed\') AND o.scheduled_at IS NOT NULL AND o.scheduled_at >= ?',
            [$occurrenceId, (int) $staff->getId(), self::utc((new \DateTimeImmutable())->modify('-' . self::BACK_DAYS . ' days'))]);
        $subscription = $sid !== false ? $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find((int) $sid) : null;

        return $subscription ?? throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Date introuvable.', 'occurrenceId');
    }

    /** @return array<string, mixed> une de ses dates, relue après le geste */
    private function one(int $staffId, int $occurrenceId, string $locale): array
    {
        return $this->rows($staffId, 'o.id = ?', [$occurrenceId], $locale)[0] ?? throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Date introuvable.', 'occurrenceId');
    }

    /** @return list<array<string, mixed>> */
    private function items(int $staffId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $locale): array
    {
        return $this->rows($staffId, 'o.scheduled_at >= ? AND o.scheduled_at < ?', [self::utc($start), self::utc($end)], $locale);
    }

    /**
     * Ses dates, vues par lui : jamais un champ de plus que ceux-ci (liste blanche).
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    /**
     * Lot ABO-4 : les dates de l'intervenant pour son lien d'agenda, dans la fenêtre de son écran (30 jours en arrière, 14 devant) et avec
     * les mêmes champs (prénom et adresse de la visite seulement).
     *
     * @return list<array<string, mixed>>
     */
    public function feedRows(int $staffId, string $locale): array
    {
        $now = new \DateTimeImmutable();

        return $this->rows($staffId, 'o.scheduled_at >= ? AND o.scheduled_at < ?', [self::utcText($now->modify('-' . self::BACK_DAYS . ' days')), self::utcText($now->modify('+' . self::AHEAD_DAYS . ' days'))], $locale);
    }

    private static function utcText(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function rows(int $staffId, string $where, array $params, string $locale): array
    {
        $rows = $this->conn()->fetchAllAssociative('SELECT o.id, o.benefit_id, o.slot_id, o.scheduled_at, o.scheduled_end, o.status, o.done_at, o.proof_path, b.at_customer, b.location AS benefit_location,
                sl.location AS slot_location, u.firstname, a.address AS line1, a.complement AS line2, a.codepostal, a.city, a.province, a.country
            FROM benefit_occurrence o JOIN benefit b ON b.id = o.benefit_id LEFT JOIN benefit_slot sl ON sl.id = o.slot_id JOIN subscription s ON s.id = o.subscription_id
            LEFT JOIN "user" u ON u.id = s.user_id LEFT JOIN adress a ON a.id = s.address_id
            WHERE ' . $where . ' AND ' . AboStaffService::EFFECTIVE . ' = ? AND o.status IN (\'scheduled\', \'done\', \'missed\') AND o.scheduled_at IS NOT NULL
            ORDER BY o.scheduled_at, o.id LIMIT ' . self::MAX, [...$params, $staffId]);

        return array_map(fn (array $r) => [
            'occurrenceId' => (int) $r['id'], 'benefitId' => (int) $r['benefit_id'], 'name' => $this->name((int) $r['benefit_id'], $locale), 'status' => (string) $r['status'],
            'slotId' => $r['slot_id'] !== null ? (int) $r['slot_id'] : null, 'startsAt' => $this->at($r['scheduled_at']), 'endsAt' => $this->at($r['scheduled_end']),
            'location' => $r['slot_location'] ?? $r['benefit_location'], 'doneAt' => $this->at($r['done_at']), 'hasProof' => $r['proof_path'] !== null,
            'customer' => ['firstname' => $r['firstname']],
            'address' => $r['at_customer'] && $r['line1'] !== null ? ['line1' => $r['line1'], 'line2' => $r['line2'], 'postalCode' => $r['codepostal'], 'city' => $r['city'],
                'province' => $r['province'], 'country' => $r['country']] : null,
        ], $rows);
    }

    private function name(int $benefitId, string $locale): string
    {
        return $this->names[$benefitId . $locale] ??= (string) $this->benefits->benefits()->find($benefitId)?->getName($locale);
    }

    private function at(mixed $utc): ?string
    {
        return $utc === null ? null : $this->clock->atom(new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')));
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private static function by(User $staff): string
    {
        return trim((string) $staff->getFirstname()) ?: (string) $staff->getUserIdentifier();
    }

    /** Un jour du magasin dans la fenêtre : de 30 jours en arrière à `$ahead` jours devant @throws AboException 422 DATE_OUT_OF_RANGE */
    private static function assertWindow(\DateTimeImmutable $day, \DateTimeZone $tz, int $ahead, string $field): void
    {
        $today = new \DateTimeImmutable('today', $tz);
        if ($day < $today->modify('-' . self::BACK_DAYS . ' days') || $day > $today->modify('+' . $ahead . ' days')) {
            throw new AboException(422, 'DATE_OUT_OF_RANGE', sprintf('Une date de %d jours en arrière à %d jours devant.', self::BACK_DAYS, self::AHEAD_DAYS), $field);
        }
    }

    /** @throws AboException 422 DATE_INVALID */
    private static function day0(string $date, \DateTimeZone $tz): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new AboException(422, 'DATE_INVALID', 'Date attendue : AAAA-MM-JJ.', 'date');
        }

        return $date;
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
