<?php

namespace App\Services\RetentionService;

use App\Entity\Entreprise;
use App\Services\CaisseService\StoreClock;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantEntityManagerProvider;

/**
 * Durée de conservation des données de vente (lot A1, 30/10/2026). Défaut par pays, jamais plus court que le plancher légal :
 * Canada (ARC, Revenu Québec) : 6 ans après la fin de l'année à laquelle les registres se rapportent ; France et DROM (Code de
 * commerce L123-22) : 10 ans. Réglage par site en années (EasyAdmin). Fin de conservation d'une donnée = 31 décembre de son année
 * (fuseau du magasin) + N ans. AUCUNE purge automatique n'a lieu à l'échéance : décision à part. Exercice supposé = année civile.
 */
class RetentionPolicy
{
    public const LEGAL_YEARS = ['CA' => 6, 'US' => 7, 'FR' => 10, 'MQ' => 10, 'GP' => 10, 'GF' => 10, 'RE' => 10, 'YT' => 10, 'BE' => 10, 'CH' => 10, 'LU' => 10, 'MC' => 10];
    public const DEFAULT_YEARS = 10;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock)
    {
    }

    public static function legalYears(?string $country): int
    {
        return self::LEGAL_YEARS[strtoupper((string) $country)] ?? self::DEFAULT_YEARS;
    }

    /** @return array{years: int, legalYears: int, country: ?string, source: string, writeMode: string, purge: string} */
    /** Lecture directe en base (jamais l'instantané de l'EntityManager : la garde Doctrine doit voir un réglage changé dans la même requête) */
    public function settings(): array
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative('SELECT e.data_retention_years, e.retention_write_mode, a.country FROM entreprise e LEFT JOIN address_entreprise a ON a.entreprise_id = e.id ORDER BY e.id LIMIT 1');

        return self::compute($row ? (int) $row['data_retention_years'] ?: null : null, $row['retention_write_mode'] ?? 'observe', TaxEngine::normalizeAddress(['country' => $row['country'] ?? null])['country'] ?? null);
    }

    public static function settingsOf(?Entreprise $e): array
    {
        return self::compute($e?->getDataRetentionYears(), $e?->getRetentionWriteMode() ?? 'observe', TaxEngine::normalizeAddress(['country' => $e?->getAddressEntreprise()?->getCountry()])['country'] ?? null);
    }

    private static function compute(?int $configured, string $writeMode, ?string $country): array
    {
        $legal = self::legalYears($country);

        return ['years' => max($legal, $configured ?? $legal), 'legalYears' => $legal, 'country' => $country, 'source' => $configured !== null && $configured >= $legal ? 'setting' : 'country',
            'writeMode' => $writeMode === 'block' ? 'block' : 'observe', 'purge' => 'none', 'fiscalYear' => 'calendar'];
    }

    /** Fin de conservation d'une donnée datée : 31 décembre (fuseau du magasin) de son année + N ans */
    public function endOfRetention(\DateTimeInterface $at): \DateTimeImmutable
    {
        $tz = $this->clock->timezone();
        $year = (int) \DateTimeImmutable::createFromInterface($at)->setTimezone($tz)->format('Y') + $this->settings()['years'];

        return new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year), $tz);
    }

    public function isRetained(\DateTimeInterface $at): bool
    {
        return $this->endOfRetention($at) > new \DateTimeImmutable();
    }
}
