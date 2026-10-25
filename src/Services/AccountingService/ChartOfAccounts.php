<?php

namespace App\Services\AccountingService;

use App\Entity\Entreprise;
use App\Services\TenantEntityManagerProvider;

/**
 * Plan de comptes du site (F14, 29/10/2026), unique par site, réglé dans EasyAdmin (une ligne « cle=numero;Nom »), plan par
 * défaut québécois. Service commun : la caisse l'utilise aujourd'hui, l'export de la boutique en ligne et le module comptable
 * de l'application de gestion l'utiliseront ensuite (comptes TVH par province, livraison, frais Stripe, commission, virements
 * Stripe déjà prévus). Les clés dynamiques (sales_rate_<taux>, tax_hst_<province>, tax_vat_<taux>) retombent sur la clé générique.
 */
class ChartOfAccounts
{
    /** clé → [numéro, nom] */
    public const DEFAULTS = [
        'sales' => ['4000', 'Ventes de marchandises'],
        'returns' => ['4080', 'Retours et remboursements sur ventes'],
        'discounts' => ['4090', 'Remises accordées'],
        'loyalty_rewards' => ['4095', 'Récompenses fidélité'],
        'shipping_revenue' => ['4100', 'Frais de livraison facturés'],
        'tax_gst' => ['2310', 'TPS à payer'],
        'tax_qst' => ['2320', 'TVQ à payer'],
        'tax_hst' => ['2330', 'TVH à payer'],
        'tax_pst' => ['2340', 'TVP à payer'],
        'tax_vat' => ['2350', 'TVA collectée'],
        'tax_other' => ['2390', 'Autres taxes à payer'],
        'cash' => ['1010', 'Caisse'],
        'bank' => ['1020', 'Banque'],
        'card_clearing' => ['1030', 'Cartes à encaisser'],
        'interac_clearing' => ['1035', 'Interac à encaisser'],
        'stripe_clearing' => ['1040', 'Stripe à recevoir'],
        'paypal_clearing' => ['1045', 'PayPal à recevoir'],
        'stripe_fees' => ['6210', 'Frais Stripe'],
        'commission' => ['6220', 'Commissions de plateforme'],
        'stripe_payout' => ['1020', 'Banque'],
        'gift_card_liability' => ['2400', 'Cartes-cadeaux en circulation'],
        'store_credit_liability' => ['2410', 'Avoirs clients'],
        'coupon_receivable' => ['1050', 'Coupons de fabricant à recevoir'], // F17 (01/11/2026) : part des ventes réglée par coupon, remboursée par le fabricant
        'cash_discrepancy' => ['6900', 'Écarts de caisse'],
        'cash_rounding' => ['6910', 'Arrondis des espèces'],
        'suspense' => ['9999', 'Compte d\'attente'],
    ];
    /** Codes de taxe reconnus par libellé (table du site ou Stripe Tax) */
    public const TAX_LABELS = ['TPS' => 'gst', 'GST' => 'gst', 'TVQ' => 'qst', 'QST' => 'qst', 'TVH' => 'hst', 'HST' => 'hst', 'TVP' => 'pst', 'PST' => 'pst', 'TVA' => 'vat', 'VAT' => 'vat'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array<string, array{number: string, name: string, custom: bool}> plan effectif du site */
    public function accounts(): array
    {
        return self::accountsOf($this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]));
    }

    public static function accountsOf(?Entreprise $e): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => [$number, $name]) {
            $out[$key] = ['number' => $number, 'name' => $name, 'custom' => false];
        }
        foreach (preg_split('/\r?\n/', (string) $e?->getAccountingAccounts()) ?: [] as $line) {
            if (!preg_match('/^\s*([a-z0-9_]+)\s*=\s*([^;]+?)\s*(?:;\s*(.+?))?\s*$/i', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            $out[$key] = ['number' => trim($m[2]), 'name' => isset($m[3]) && trim($m[3]) !== '' ? trim($m[3]) : ($out[$key]['name'] ?? $key), 'custom' => true];
        }

        return $out;
    }

    /** Préfixes des clés dynamiques acceptées : sales_rate_<taux>, tax_hst_<province>, tax_pst_<province>, tax_vat_<taux> */
    public const DYNAMIC_PREFIXES = ['sales_rate_' => '/^\d+(\.\d+)?$/', 'tax_hst_' => '/^[A-Z]{2}$/i', 'tax_pst_' => '/^[A-Z]{2}$/i', 'tax_vat_' => '/^\d+(\.\d+)?$/'];

    /**
     * Erreurs d'un réglage « cle=numero;Nom » (une ligne par compte) : ligne mal formée, clé inconnue, doublon. Vide = plan par défaut.
     *
     * @return list<string> messages (vide si tout est valide)
     */
    public static function validate(?string $text): array
    {
        $errors = [];
        $seen = [];
        foreach (preg_split('/\r?\n/', (string) $text) ?: [] as $i => $line) {
            $n = $i + 1;
            if (trim($line) === '') {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*([^;]+?)\s*(?:;\s*(.+?))?\s*$/', $line, $m)) {
                $errors[] = sprintf('Ligne %d : format attendu « cle=numero;Nom » (p. ex. sales=4000;Ventes)', $n);
                continue;
            }
            $key = strtolower($m[1]);
            if (!self::isKnownKey($key)) {
                $errors[] = sprintf('Ligne %d : clé inconnue « %s » (clés acceptées : %s, ou dynamiques %s)', $n, $m[1], implode(', ', array_keys(self::DEFAULTS)), implode(', ', array_map(fn ($p) => $p . '…', array_keys(self::DYNAMIC_PREFIXES))));
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = sprintf('Ligne %d : clé « %s » en double (déjà définie ligne %d)', $n, $m[1], $seen[$key]);
                continue;
            }
            $seen[$key] = $n;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-\/]{0,19}$/', trim($m[2]))) {
                $errors[] = sprintf('Ligne %d : numéro de compte invalide « %s » (1 à 20 caractères : chiffres, lettres, point, tiret)', $n, trim($m[2]));
            }
        }

        return $errors;
    }

    public static function isKnownKey(string $key): bool
    {
        if (isset(self::DEFAULTS[$key])) {
            return true;
        }
        foreach (self::DYNAMIC_PREFIXES as $prefix => $pattern) {
            if (str_starts_with($key, $prefix) && preg_match($pattern, substr($key, strlen($prefix)))) {
                return true;
            }
        }

        return false;
    }

    /** Compte d'une clé, avec repli de la clé dynamique sur sa clé générique (sales_rate_5 → sales, tax_hst_ON → tax_hst, tax_vat_20 → tax_vat) */
    public function account(string $key): array
    {
        $accounts = $this->accounts();
        if (isset($accounts[$key])) {
            return $accounts[$key] + ['key' => $key];
        }
        foreach (['sales_rate_' => 'sales', 'tax_hst_' => 'tax_hst', 'tax_vat_' => 'tax_vat', 'tax_pst_' => 'tax_pst'] as $prefix => $generic) {
            if (str_starts_with($key, $prefix)) {
                return $accounts[$generic] + ['key' => $generic];
            }
        }

        return $accounts['suspense'] + ['key' => 'suspense'];
    }

    /** Clé de compte d'une taxe d'après son libellé (TPS, TVQ, TVH, TVP, TVA…), avec province ou taux dynamiques */
    public static function taxKey(string $label, ?string $province = null, ?float $rate = null): string
    {
        $code = self::TAX_LABELS[strtoupper(trim(preg_replace('/[^A-Za-z]/', '', $label) ?? ''))] ?? 'other';
        if ($code === 'hst' && $province) {
            return 'tax_hst_' . strtoupper($province);
        }
        if ($code === 'vat' && $rate !== null) {
            return 'tax_vat_' . rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.');
        }

        return 'tax_' . $code;
    }
}
