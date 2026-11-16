<?php

namespace App\Services\Agency\Assistance;

/**
 * Les SECTIONS qu'un commerçant peut ouvrir à l'assistance de son agence (lot AG-ASSIST, 16/11/2026, `docs/ecrans-et-contextes.md`
 * § 8). Chaque section réunit des écrans du registre (`ScreenRegistry`) fermés à l'agence ; l'assistance vaut pour CETTE section
 * seulement. Les écrans d'EasyAdmin (`admin.*`) y sont déjà rangés pour le lot AG-3c (même mécanisme).
 *
 * Jamais demandables : le paiement, les clés, le courriel expéditeur, les comptes et les rôles, Mémoires Vivantes et Boussole (projet à
 * part). La prévention des pertes s'ouvre en LECTURE SEULE : décider d'un constat, classer sans suite, la notice aux salariés restent au
 * commerçant (décision du 16/11/2026).
 */
final class AssistanceSections
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const MODES = [self::READ, self::WRITE];

    /** section => [écrans du registre, écriture possible, libellé fr, libellé en] */
    public const SECTIONS = [
        'commandes' => ['screens' => ['ventes.commandes', 'commandes.a_expedier', 'admin.commandes', 'admin.expeditions', 'admin.abonnements'], 'writable' => true,
            'fr' => 'Commandes', 'en' => 'Orders'],
        'clients' => ['screens' => ['clients.demandes', 'clients.reservations', 'admin.clients'], 'writable' => true,
            'fr' => 'Clients', 'en' => 'Customers'],
        'caisse' => ['screens' => ['admin.caisse'], 'writable' => true, 'fr' => 'Caisse', 'en' => 'Point of sale'],
        'stock' => ['screens' => ['stock', 'stock.low', 'admin.stock'], 'writable' => true, 'fr' => 'Stock', 'en' => 'Stock'],
        'gestion' => ['family' => 'gestion', 'screens' => ['admin.achats'], 'writable' => true, 'fr' => 'Gestion', 'en' => 'Management'],
        'pertes' => ['family' => 'pertes', 'screens' => [], 'writable' => false, 'fr' => 'Prévention des pertes', 'en' => 'Loss prevention'],
        'assistance' => ['screens' => ['assistance', 'assistance.demande'], 'writable' => true, 'fr' => 'Assistance aux clients', 'en' => 'Customer support'],
    ];

    /** Les sections d'un écran du registre (un écran peut appartenir à deux sections : le stock est aussi un écran de la gestion) */
    public static function of(string $screen, ?string $family): array
    {
        $out = [];
        foreach (self::SECTIONS as $section => $spec) {
            if (in_array($screen, $spec['screens'], true) || (($spec['family'] ?? null) !== null && $spec['family'] === $family)) {
                $out[] = $section;
            }
        }

        return $out;
    }

    public static function label(string $section, string $language): string
    {
        return (string) (self::SECTIONS[$section][$language === 'en' ? 'en' : 'fr'] ?? $section);
    }
}
