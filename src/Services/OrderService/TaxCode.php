<?php

namespace App\Services\OrderService;

use App\Entity\Tax;
use App\Services\AccountingService\ChartOfAccounts;

/**
 * Code stable d'une taxe et valeurs gelées sur la commande (PAYS-1, 12/11/2026).
 * Codes : gst | qst | hst | pst | vat | other (même vocabulaire que ChartOfAccounts ; « sales » viendra avec PAYS-3).
 */
final class TaxCode
{
    public const CODES = ['gst', 'qst', 'hst', 'pst', 'vat', 'other'];

    /** Code d'une taxe : tax.code s'il est renseigné, sinon déduit du libellé */
    public static function of(Tax $tax): string
    {
        $code = $tax->getCode();

        return $code !== null && in_array($code, self::CODES, true) ? $code : self::fromLabel((string) $tax->getName());
    }

    /** Même règle que ChartOfAccounts::taxKey : lettres du libellé, en majuscules (TPS, GST, TVQ…), sinon « other » */
    public static function fromLabel(?string $label): string
    {
        return ChartOfAccounts::TAX_LABELS[strtoupper(trim(preg_replace('/[^A-Za-z]/', '', (string) $label) ?? ''))] ?? 'other';
    }

    /** Taux décimal (0.09975) → millionièmes (99750) */
    public static function ppm(?float $rate): ?int
    {
        return $rate === null ? null : (int) round($rate * 1000000);
    }

    /** Subdivision d'une adresse normalisée (TaxEngine::normalizeAddress) : CA-QC, US-NY, FR ; null sans pays */
    public static function jurisdiction(?array $address): ?string
    {
        $country = strtoupper(trim((string) ($address['country'] ?? '')));
        if ($country === '') {
            return null;
        }
        $province = strtoupper(trim((string) ($address['province'] ?? '')));

        return $province !== '' ? $country . '-' . $province : $country;
    }

    /** Juridiction d'une taxe Stripe (« CA QC », « US NY ») → CA-QC */
    public static function stripeJurisdiction(?string $jurisdiction): ?string
    {
        $parts = preg_split('/\s+/', strtoupper(trim((string) $jurisdiction))) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        return $parts === [] ? null : implode('-', array_slice($parts, 0, 2));
    }
}
