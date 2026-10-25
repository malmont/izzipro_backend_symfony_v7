<?php

namespace App\Services\PosCouponService;

/**
 * Lecture des codes-barres de coupons de fabricant nord-américains (F17, 01/11/2026) : GS1 DataBar Expanded, identificateur
 * d'application 8110 (North American Coupon Application Guideline, GS1 US/GS1 Canada) et ancien UPC-A à système numérique 5.
 * Lecture fiable : préfixe d'entreprise GS1 du fabricant, code d'offre, valeur de l'économie en cents quand le code de valeur
 * est 0 (montant fixe), date d'expiration et numéro de série s'ils sont présents. Non fiable sans les données du fabricant :
 * exigences d'achat, codes famille, « article gratuit », « deux pour un », pourcentage. Structure à valider sur de vrais coupons.
 */
final class CouponBarcodeParser
{
    public const FORMAT_DATABAR = 'gs1_databar_8110';
    public const FORMAT_UPC5 = 'upc_a_5';
    /** Codes de valeur du champ 9 (NACAG) : 0 = montant fixe en cents (défaut) ; les autres exigent une saisie */
    public const SAVE_VALUE_CODES = ['0' => 'fixed_amount', '1' => 'free_item', '2' => 'buy_x_get_y', '5' => 'percent_off', '6' => 'percent_off_item'];

    /** @return array{format: string, companyPrefix: ?string, offerCode: ?string, saveValue: ?int, saveValueCode: ?string, amount: ?int, amountReliable: bool, expiresAt: ?string, startsAt: ?string, serial: ?string, familyCode: ?string, purchaseRequirement: ?array, valueCode: ?string, retailerId: ?string, raw: string, complete: bool} */
    public static function parse(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', str_replace(['(8110)', ']e0', ']C1', ']E0'], ['8110', '', '', ''], trim($raw)));
        if ($digits === null || $digits === '') {
            throw new CouponBarcodeException('Code-barres vide ou sans chiffre');
        }
        if (str_starts_with($digits, '8110') && strlen($digits) >= 24) {
            return self::parseDataBar($digits, $raw);
        }
        if (strlen($digits) === 12 && $digits[0] === '5') {
            return self::parseUpc5($digits, $raw);
        }
        throw new CouponBarcodeException('Format inconnu : GS1 DataBar coupon (8110…) ou UPC-A système 5 (12 chiffres) attendu');
    }

    private static function parseDataBar(string $d, string $raw): array
    {
        $pos = 4;
        $take = function (int $n, string $field) use (&$pos, $d): string {
            if ($pos + $n > strlen($d)) {
                throw new CouponBarcodeException(sprintf('Code-barres tronqué (champ %s)', $field));
            }
            $v = substr($d, $pos, $n);
            $pos += $n;

            return $v;
        };
        $vli = (int) $take(1, 'longueur du préfixe');
        if ($vli > 6) {
            throw new CouponBarcodeException('Indicateur de longueur du préfixe invalide');
        }
        $out = ['format' => self::FORMAT_DATABAR, 'companyPrefix' => $take($vli + 6, 'préfixe d\'entreprise'), 'offerCode' => $take(6, 'code d\'offre'), 'saveValue' => null, 'saveValueCode' => null,
            'amount' => null, 'amountReliable' => false, 'expiresAt' => null, 'startsAt' => null, 'serial' => null, 'familyCode' => null, 'purchaseRequirement' => null, 'valueCode' => null, 'retailerId' => null, 'raw' => $raw, 'complete' => true];
        $svLen = (int) $take(1, 'longueur de la valeur');
        $out['saveValue'] = $svLen > 0 ? (int) $take($svLen, 'valeur de l\'économie') : 0;
        $prLen = (int) $take(1, 'longueur de l\'exigence d\'achat');
        $pr = $prLen > 0 ? (int) $take($prLen, 'exigence d\'achat') : 0;
        $prCode = $take(1, 'code d\'exigence');
        $out['familyCode'] = $take(3, 'code famille');
        $out['purchaseRequirement'] = ['value' => $pr, 'code' => $prCode];
        // Champs facultatifs, chacun introduit par un identifiant de donnée (NACAG) ; un identifiant inconnu arrête la lecture (complete: false)
        while ($pos < strlen($d)) {
            $id = $d[$pos++];
            switch ($id) {
                case '1': // règles additionnelles + 2e exigence d'achat (+ préfixe propre si l'indicateur n'est pas 9)
                    $take(1, 'règles additionnelles');
                    $n = (int) $take(1, 'longueur 2e exigence');
                    $n > 0 && $take($n, '2e exigence');
                    $take(1, 'code 2e exigence');
                    $take(3, 'famille 2e exigence');
                    $v = (int) $take(1, 'longueur du 2e préfixe');
                    $v !== 9 && $take($v + 6, '2e préfixe');
                    break;
                case '2': // 3e exigence d'achat
                    $n = (int) $take(1, 'longueur 3e exigence');
                    $n > 0 && $take($n, '3e exigence');
                    $take(1, 'code 3e exigence');
                    $take(3, 'famille 3e exigence');
                    $v = (int) $take(1, 'longueur du 3e préfixe');
                    $v !== 9 && $take($v + 6, '3e préfixe');
                    break;
                case '3':
                    $out['expiresAt'] = self::date($take(6, 'date d\'expiration'));
                    break;
                case '4':
                    $out['startsAt'] = self::date($take(6, 'date de début'));
                    break;
                case '5':
                    $v = (int) $take(1, 'longueur du numéro de série');
                    $out['serial'] = $take($v + 6, 'numéro de série');
                    break;
                case '6':
                    $v = (int) $take(1, 'longueur du détaillant');
                    $out['retailerId'] = $take($v + 6, 'identifiant du détaillant');
                    break;
                case '9':
                    $out['saveValueCode'] = $take(1, 'code de valeur');
                    $take(3, 'indicateurs');
                    break;
                default:
                    $out['complete'] = false;
                    $pos = strlen($d);
            }
        }
        $out['valueCode'] = $out['saveValueCode'] !== null ? (self::SAVE_VALUE_CODES[$out['saveValueCode']] ?? 'unknown') : 'fixed_amount';
        $out['amountReliable'] = $out['complete'] && $out['saveValue'] > 0 && ($out['saveValueCode'] === null || $out['saveValueCode'] === '0');
        $out['amount'] = $out['amountReliable'] ? $out['saveValue'] : null;

        return $out;
    }

    /** Ancien UPC-A à système 5 : 5 + fabricant (5) + famille (3) + code de valeur (2) + contrôle ; le montant vient d'une table que l'on ne reproduit pas */
    private static function parseUpc5(string $d, string $raw): array
    {
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $sum += (int) $d[$i] * ($i % 2 === 0 ? 3 : 1);
        }
        if ((10 - $sum % 10) % 10 !== (int) $d[11]) {
            throw new CouponBarcodeException('Chiffre de contrôle UPC-A invalide');
        }

        return ['format' => self::FORMAT_UPC5, 'companyPrefix' => substr($d, 1, 5), 'offerCode' => null, 'saveValue' => null, 'saveValueCode' => null, 'amount' => null, 'amountReliable' => false,
            'expiresAt' => null, 'startsAt' => null, 'serial' => null, 'familyCode' => substr($d, 6, 3), 'purchaseRequirement' => null, 'valueCode' => substr($d, 9, 2), 'retailerId' => null, 'raw' => $raw, 'complete' => true];
    }

    private static function date(string $yymmdd): ?string
    {
        $dt = \DateTimeImmutable::createFromFormat('!ymd', $yymmdd);

        return $dt !== false && $dt->format('ymd') === $yymmdd ? $dt->format('Y-m-d') : null;
    }

    /** Chiffre de contrôle UPC-A des 11 premiers chiffres (aide aux tests et à la documentation) */
    public static function upcCheckDigit(string $eleven): string
    {
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $sum += (int) $eleven[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
