<?php

namespace App\Import\Shopify;

/**
 * Lecteur du fichier d'export des CLIENTS d'une boutique Shopify (CSV), lot IMP-2. Il ne lit que le fichier : rien du site.
 *
 * Forme du fichier : une ligne par client, avec son adresse par défaut. Ne sont lus que le prénom, le nom, le courriel, le téléphone,
 * l'adresse par défaut et « accepte les courriels commerciaux ». Les notes, les étiquettes, le total dépensé et le nombre de commandes
 * de l'ancienne boutique ne sont PAS lus : ce sont des commentaires et des chiffres internes, qui n'ont rien à faire dans la nouvelle.
 * Séparateur : virgule (un point-virgule ou une tabulation, produits par un tableur, sont reconnus à l'en-tête). Encodage : UTF-8,
 * avec ou sans marque d'ordre des octets ; un autre encodage est refusé (`NOT_UTF8`).
 */
final class ShopifyCustomerCsv
{
    public const MAX_ROWS = 5000;
    /** en-tête du fichier (en minuscules) → clé interne ; l'ancien format de l'export a d'autres noms pour trois colonnes */
    private const COLUMNS = [
        'customer id' => 'id', 'first name' => 'firstname', 'last name' => 'lastname', 'email' => 'email', 'phone' => 'phone',
        'accepts email marketing' => 'marketing', 'accepts marketing' => 'marketing',
        'default address company' => 'company', 'company' => 'company', 'default address address1' => 'address1', 'address1' => 'address1',
        'default address address2' => 'address2', 'address2' => 'address2', 'default address city' => 'city', 'city' => 'city',
        'default address province code' => 'province', 'province code' => 'province', 'province' => 'province',
        'default address country code' => 'country', 'country code' => 'country', 'country' => 'country',
        'default address zip' => 'zip', 'zip' => 'zip', 'default address phone' => 'addressPhone',
    ];
    public const NOT_A_CUSTOMER_FILE = 'NOT_A_CUSTOMER_FILE';
    public const TOO_MANY_ROWS = 'TOO_MANY_ROWS';
    public const EMPTY_FILE = 'EMPTY_FILE';
    public const NOT_UTF8 = 'NOT_UTF8';

    /**
     * @return array{error: ?string, rows: int, customers: list<array<string, mixed>>}
     *   client : { line, ref, firstname, lastname, email, phone, marketing: bool, address: { company, address1, address2, city, province,
     *   country, zip, phone } } ; `email` tel que le fichier l'écrit (le contrôle et la mise en minuscules sont faits par le plan)
     */
    public function read(string $path): array
    {
        $none = static fn (string $error, int $rows = 0): array => ['error' => $error, 'rows' => $rows, 'customers' => []];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return $none(self::EMPTY_FILE);
        }
        try {
            $first = fgets($handle);
            if ($first === false || trim($first) === '') {
                return $none(self::EMPTY_FILE);
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $separator = ',';
            foreach ([';', "\t"] as $candidate) {
                if (substr_count($first, $candidate) > substr_count($first, $separator)) {
                    $separator = $candidate;
                }
            }
            $map = [];
            foreach (str_getcsv(rtrim($first, "\r\n"), $separator, '"', '') as $i => $name) {
                $key = self::COLUMNS[mb_strtolower(trim((string) $name))] ?? null;
                if ($key !== null && !isset($map[$key])) {
                    $map[$key] = $i;
                }
            }
            // un export de clients porte le courriel et le nom ; un export de produits (« Handle », « Title ») n'en est pas un
            if (!isset($map['email']) || (!isset($map['firstname']) && !isset($map['lastname']))) {
                return $none(self::NOT_A_CUSTOMER_FILE);
            }
            $customers = [];
            $rows = 0;
            $line = 1; // l'en-tête
            while (($cells = fgetcsv($handle, null, $separator, '"', '')) !== false) {
                ++$line;
                if ($cells === [null] || $cells === []) {
                    continue;
                }
                foreach ($cells as $cell) {
                    if ($cell !== null && !mb_check_encoding((string) $cell, 'UTF-8')) {
                        return $none(self::NOT_UTF8, $rows); // un texte mal encodé serait refusé par la base au milieu de l'import
                    }
                }
                if (++$rows > self::MAX_ROWS) {
                    return $none(self::TOO_MANY_ROWS, $rows);
                }
                $get = static fn (string $key): string => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
                $customers[] = [
                    'line' => $line, 'ref' => ltrim($get('id'), "'"), // l'export écrit parfois l'identifiant avec une apostrophe devant
                    'firstname' => $get('firstname'), 'lastname' => $get('lastname'), 'email' => $get('email'), 'phone' => ltrim($get('phone'), "'"),
                    'marketing' => in_array(mb_strtolower($get('marketing')), ['yes', 'true', '1', 'oui'], true),
                    'address' => ['company' => $get('company'), 'address1' => $get('address1'), 'address2' => $get('address2'), 'city' => $get('city'), 'province' => $get('province'),
                        'country' => $get('country'), 'zip' => ltrim($get('zip'), "'"), 'phone' => ltrim($get('addressPhone'), "'")],
                ];
            }

            return ['error' => $rows === 0 ? self::EMPTY_FILE : null, 'rows' => $rows, 'customers' => $customers];
        } finally {
            fclose($handle);
        }
    }
}
