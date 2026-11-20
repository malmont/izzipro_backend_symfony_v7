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

    /**
     * Sections qu'on peut demander AUJOURD'HUI par l'API : la caisse et le stock n'ont d'écrans que dans EasyAdmin, et le module Assistance
     * n'ouvre ses tickets qu'à un compte du site ; ils viendront avec AG-3c (même mécanisme).
     */
    public const API_AVAILABLE = ['commandes', 'clients', 'gestion', 'pertes'];

    /**
     * LISTE BLANCHE des écritures permises en mode « modifier », section par section ([méthode, gabarit de la route]). Toute autre
     * écriture d'une section est refusée, même en « modifier » : fermé par défaut. Restent donc fermés : annuler une vente (c'est un
     * remboursement), acheter une étiquette, capturer, rembourser, supprimer un contact ou une demande ; dans la gestion : la console IA,
     * les fils d'action, les documents lus par l'IA, les rapports, les avis rédigés (ils consomment les unités du commerçant), le lexique,
     * les préférences de notification (celles d'un compte) et les réglages. Les pertes : aucune écriture. L'essai
     * `AssistanceTest::testEveryWriteOfASectionIsListed` échoue sur toute écriture d'une section qui n'est ni permise ni refusée ici.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const WRITES = [
        'commandes' => [
            ['POST', '/api/shipping/parcels'],
        ],
        'clients' => [
            // (confirmer un rendez-vous n'est pas permis : la route exige un compte du site)
            ['PUT', '/api/contacts/{id}'],
            ['PATCH', '/api/form-submissions/{id}'],
        ],
        'gestion' => [
            ['DELETE', '/api/collections/{id}'],
            ['DELETE', '/api/commandes/{id}'],
            ['DELETE', '/api/commandes/{id}/frais-de-port'],
            ['DELETE', '/api/commandes/{id}/lignes/{lineId}'],
            ['DELETE', '/api/commandes/{id}/products/{productId}'],
            ['DELETE', '/api/fournisseurs/{id}'],
            ['DELETE', '/api/gestion/campagnes/{id}'],
            ['DELETE', '/api/gestion/prets/{id}'], // lot FIS-1 : les prêts, comme les mouvements prévus de la trésorerie
            ['DELETE', '/api/gestion/prets/{id}/remboursements/{index}'],
            ['DELETE', '/api/gestion/tresorerie/mouvements/{id}'],
            ['DELETE', '/api/gestion/tresorerie/soldes/{id}'],
            ['DELETE', '/api/gestion/voyages/{id}'],
            ['DELETE', '/api/notes-de-frais/{id}'],
            ['DELETE', '/api/transporteurs/{id}'],
            ['PATCH', '/api/collections/{id}'],
            ['PATCH', '/api/commandes/{id}'],
            ['PATCH', '/api/commandes/{id}/frais-de-port'],
            ['PATCH', '/api/commandes/{id}/lignes/{lineId}'],
            ['PATCH', '/api/fournisseurs/{id}'],
            ['PATCH', '/api/gestion/campagnes/{id}'],
            ['PATCH', '/api/gestion/prets/{id}'],
            ['PATCH', '/api/gestion/tresorerie/mouvements/{id}'],
            ['PATCH', '/api/gestion/tresorerie/soldes/{id}'],
            ['PATCH', '/api/gestion/voyages/{id}'],
            ['PATCH', '/api/periodes/{id}'],
            ['PATCH', '/api/transporteurs/{id}'],
            ['POST', '/api/collections/{id}/commandes'],
            ['POST', '/api/collections/{id}/notes-de-frais'],
            ['POST', '/api/commandes'],
            ['POST', '/api/commandes/{id}/etiquettes/codes'],
            ['POST', '/api/commandes/{id}/etiquettes/pdf'],
            ['POST', '/api/commandes/{id}/frais-de-port'],
            ['POST', '/api/commandes/{id}/lignes/{lineId}/reception/annuler'],
            ['POST', '/api/commandes/{id}/lignes/{lineId}/reception'],
            ['POST', '/api/commandes/{id}/products'],
            ['POST', '/api/commandes/{id}/products/rattacher'],
            ['POST', '/api/commandes/{id}/reception'],
            ['POST', '/api/createcollections'],
            ['POST', '/api/dashboard/collection/{id}/close'],
            ['POST', '/api/dashboard/collection/{id}/reopen'],
            ['POST', '/api/dashboard/commande/{id}/close'],
            ['POST', '/api/fournisseurs'],
            ['POST', '/api/gestion/accueil/vignettes/{id}/ecarter'],
            ['POST', '/api/gestion/campagnes'],
            ['POST', '/api/gestion/conseils/{id}/ecarter'],
            ['POST', '/api/gestion/conseils/{id}/fait'],
            ['POST', '/api/gestion/locations/grilles/annuler'],
            ['POST', '/api/gestion/locations/grilles/apercu'],
            ['POST', '/api/gestion/locations/grilles/appliquer'],
            ['POST', '/api/gestion/locations/promotions/apercu'],
            ['POST', '/api/gestion/locations/promotions/appliquer'],
            ['POST', '/api/gestion/locations/promotions/terminer'],
            ['POST', '/api/gestion/preparation/simulation'],
            ['POST', '/api/gestion/preparation/valider'],
            ['POST', '/api/gestion/prix/annuler'],
            ['POST', '/api/gestion/prix/apercu'],
            ['POST', '/api/gestion/prix/appliquer'],
            ['POST', '/api/gestion/promotions/apercu'],
            ['POST', '/api/gestion/promotions/appliquer'],
            ['POST', '/api/gestion/promotions/terminer'],
            ['POST', '/api/gestion/promotions/variantes/apercu'],
            ['POST', '/api/gestion/promotions/variantes/appliquer'],
            ['POST', '/api/gestion/prets/apercu'],
            ['POST', '/api/gestion/prets/{id}/remboursements'],
            ['POST', '/api/gestion/prets'],
            ['POST', '/api/gestion/promotions/variantes/terminer'],
            ['POST', '/api/gestion/reprise/annuler'],
            ['POST', '/api/gestion/reprise/appliquer'],
            ['POST', '/api/gestion/reprise/simulation'],
            ['POST', '/api/gestion/tresorerie/mouvements'],
            ['POST', '/api/gestion/tresorerie/soldes/{id}/ecart'],
            ['POST', '/api/gestion/tresorerie/soldes'],
            ['POST', '/api/gestion/voyages/rattacher'],
            ['POST', '/api/gestion/voyages'],
            ['POST', '/api/notes-de-frais'],
            ['POST', '/api/periodes/{id}/budget/copier'],
            ['POST', '/api/periodes/{id}/simulation'],
            ['POST', '/api/transporteurs'],
            ['PUT', '/api/notes-de-frais/{id}'],
            ['PUT', '/api/periodes/{id}/budget'],
        ],
        'pertes' => [],
    ];

    /** L'écriture est-elle dans la liste blanche de la section ? (chemin décodé, méthode) */
    public static function writeAllowed(string $section, string $method, string $path): bool
    {
        foreach (self::WRITES[$section] ?? [] as [$m, $template]) {
            if ($m === strtoupper($method) && preg_match(self::pattern($template), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Le gabarit d'une route (`/api/x/{id}`) en expression : un paramètre vaut un segment */
    public static function pattern(string $template): string
    {
        return '#^' . preg_replace('#\\\{[^/]+?\\\}#', '[^/]+', preg_quote($template, '#')) . '$#';
    }

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
