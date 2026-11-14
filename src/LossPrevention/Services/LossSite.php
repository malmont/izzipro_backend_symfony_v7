<?php

namespace App\LossPrevention\Services;

use App\Entity\Entreprise;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantEntityManagerProvider;

/**
 * Ce que le module lit de la fiche du site, sans jamais l'écrire : pays et région (adresse de l'entreprise, normalisés comme pour les taxes
 * et le fuseau), devise, écart de caisse toléré, fuseau du magasin.
 */
class LossSite
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock, private readonly ?TenantCurrencyProvider $currency = null)
    {
    }

    /** @return array{country: ?string, region: ?string} codes ISO (pays) et de l'État ou de la province */
    public function place(): array
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $address = TaxEngine::normalizeAddress(['country' => $e?->getAddressEntreprise()?->getCountry(), 'province' => $e?->getAddressEntreprise()?->getState()]);

        return ['country' => $address['country'] ?? null, 'region' => $address['province'] ?? null];
    }

    public function currency(): string
    {
        return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
    }

    /** Écart de caisse toléré sans motif, réglé sur la fiche du site (cents ; 0 par défaut) */
    public function discrepancyThreshold(): int
    {
        return max(0, (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT pos_discrepancy_threshold FROM entreprise ORDER BY id LIMIT 1'));
    }

    /**
     * Clôtures de caisse d'une période de journées du magasin : nombre, somme signée des écarts (compté − attendu), nombre de clôtures avec un
     * écart. Mêmes définitions et même datation (`closed_at`, bornes de `StoreClock::range`) que le bloc cashDesk des statistiques de la
     * gestion : les deux se lisent côte à côte, au cent près (test).
     *
     * @return array{closings: int, discrepancy: int, discrepancyCount: int}
     */
    public function closings(string $from, string $to): array
    {
        [$start, $end] = $this->clock->range($from, $to);
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS closings, COALESCE(SUM(discrepancy), 0) AS discrepancy, COUNT(*) FILTER (WHERE COALESCE(discrepancy, 0) <> 0) AS with_discrepancy FROM cash_register_report WHERE closed_at >= :from AND closed_at < :to',
            ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')]) ?: [];

        return ['closings' => (int) ($row['closings'] ?? 0), 'discrepancy' => (int) ($row['discrepancy'] ?? 0), 'discrepancyCount' => (int) ($row['with_discrepancy'] ?? 0)];
    }

    public function clock(): StoreClock
    {
        return $this->clock;
    }
}
