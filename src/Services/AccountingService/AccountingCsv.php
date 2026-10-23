<?php

namespace App\Services\AccountingService;

/** Écriture CSV : « generic » et « summary » pour Excel en français (UTF-8 avec BOM, « ; », virgule décimale) ; « qbo » pour QuickBooks Online (« , », point décimal, dates JJ/MM/AAAA) */
final class AccountingCsv
{
    public const BOM = "\xEF\xBB\xBF";

    /** @param list<array<string, mixed>> $rows */
    public static function write(array $header, array $rows, string $separator = ';', bool $bom = true): string
    {
        $h = fopen('php://temp', 'r+');
        if ($bom) {
            fwrite($h, self::BOM);
        }
        fputcsv($h, $header, $separator, '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($h, $row, $separator, '"', '\\', "\r\n");
        }
        rewind($h);

        return stream_get_contents($h) ?: '';
    }

    /** Montant en cents → décimal à la virgule (Excel fr) */
    public static function fr(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /** Montant en cents → décimal au point (QuickBooks) */
    public static function en(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
