<?php

namespace App\Services\GestionService;

use App\Entity\Entreprise;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Exercice du site (lot AN, partie EX, 11/11/2026, `docs/annee-gestion.md` § 1) : 12 mois à partir du 1er du mois choisi
 * (`entreprise.fiscal_year_start_month`, 1 à 12, null = 1, année civile), au fuseau du magasin. Un exercice est désigné par son année de
 * DÉBUT. Changer le réglage ne réécrit rien : tout est recalculé à la lecture (instantanés de stock et périodes fermées inchangés).
 */
class GestionFiscalYearService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    public function startMonth(): int
    {
        return $this->entreprise()?->getFiscalYearStartMonth() ?? 1;
    }

    /** @return array{0: string, 1: string} premier et dernier jour (Y-m-d) de l'exercice qui commence l'année `$startYear` */
    public static function bounds(int $startYear, int $startMonth): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $startYear, $startMonth));

        return [$from->format('Y-m-d'), $from->modify('+12 months')->modify('-1 day')->format('Y-m-d')];
    }

    /** Année de début de l'exercice qui contient `$day` */
    public static function startYearOf(\DateTimeInterface $day, int $startMonth): int
    {
        $y = (int) $day->format('Y');

        return (int) $day->format('n') >= $startMonth ? $y : $y - 1;
    }

    /** « 2026 » pour un exercice civil, « 2026-2027 » sinon */
    public static function label(int $startYear, int $startMonth): string
    {
        return $startMonth === 1 ? (string) $startYear : sprintf('%d-%d', $startYear, $startYear + 1);
    }

    /** GET /api/gestion/reglages/exercice */
    public function settings(?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $m = $this->startMonth();
        $y = self::startYearOf($today, $m);
        [$from, $to] = self::bounds($y, $m);

        return ['startMonth' => $m, 'label' => self::label($y, $m), 'current' => ['startYear' => $y, 'from' => $from, 'to' => $to, 'label' => self::label($y, $m)]];
    }

    /** PUT /api/gestion/reglages/exercice { startMonth } */
    public function put(array $data): array
    {
        $v = $data['startMonth'] ?? null;
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }
        if (!is_int($v)) {
            throw new GestionValidationException(['startMonth' => $v === null ? 'REQUIRED' : 'MUST_BE_INTEGER']);
        }
        if ($v < 1 || $v > 12) {
            throw new GestionValidationException(['startMonth' => 'OUT_OF_RANGE']);
        }
        $e = $this->entreprise();
        if ($e === null) {
            throw new GestionConflictException('ENTREPRISE_NOT_FOUND', 'Fiche entreprise du site introuvable.', 404);
        }
        $before = $this->startMonth();
        $e->setFiscalYearStartMonth($v === 1 ? null : $v);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record('gestion-reglages-exercice', (int) $e->getId(), 'update', ['startMonth' => $before], ['startMonth' => $v]);

        return $this->settings();
    }

    /**
     * Mois de début d'exercice alignés sur les périodes du site (trimestres saisonniers : mars, juin, septembre, décembre ; semestres : janvier,
     * juillet ; mois : tous)
     *
     * @return list<int>
     */
    public function alignedMonths(): array
    {
        return match ($this->calendar->frequency()) {
            'monthly' => range(1, 12),
            'semester' => [1, 7],
            default => [3, 6, 9, 12],
        };
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
    }
}
