<?php

namespace App\Services\Site;

/**
 * Liste de référence des écrans de l'espace d'administration du web qu'un courriel ou une notification du serveur peut ouvrir par un
 * lien (lot LIEN, 14/11/2026, `docs/liens-espace-administration.md`). Le serveur ne connaît pas les chemins du web : il fabrique
 * toujours la même adresse d'entrée, `<adresse de l'espace d'administration>/admin/ouvrir?screen=<code>&<paramètres>`, et le web la
 * traduit lui-même. Cette liste est le contrat : un code ou un paramètre qui n'y est pas ne part dans aucun lien.
 *
 * Formes d'un paramètre : `id` (entier > 0), `flag` (présent = 1, absent sinon), une liste fermée (une valeur), `['many' => [...]]`
 * (une ou plusieurs valeurs d'une liste fermée, séparées par des virgules, dans l'ordre de la liste). Une forme nouvelle (une date, par
 * exemple) s'ajoute ici avec son test, le jour où un lien en a besoin.
 */
final class AdminSpaceLinks
{
    public const PATH = '/admin/ouvrir';
    /**
     * SEULE exception à l'entrée unique (lot SUPPORT-WEB, décision du 14/11/2026) : l'écran où un compte invité choisit son mot de passe.
     * Le jeton à usage unique voyage dans le FRAGMENT de l'adresse (`/admin/activer#token=<jeton>`), jamais dans ses paramètres : un
     * fragment n'est envoyé à aucun serveur, il n'entre donc ni dans un journal, ni dans l'en-tête Referer.
     */
    public const ACTIVATION_PATH = '/admin/activer';

    public const FINDING_STATUSES = ['open', 'watch', 'explained', 'dismissed', 'confirmed'];
    public const FINDING_RULES = ['CASH_DISCREPANCY', 'DRAWER_NO_SALE', 'VOID_AFTER_SALE', 'REFUND_PATTERN', 'DISCOUNT_PATTERN', 'PRICE_OVERRIDE', 'APPROVAL_PATTERN'];
    public const TICKET_STATUSES = ['new', 'open', 'waiting_customer', 'closed'];

    /** Code d'écran => `params` (nom => forme), `required` (paramètres sans lesquels le lien n'a pas de sens) */
    public const LINKS = [
        // Prévention des pertes (`docs/prevention-pertes.md`)
        'pertes.home' => [],
        'pertes.constats' => ['params' => ['isNew' => 'flag', 'status' => ['many' => self::FINDING_STATUSES], 'rule' => self::FINDING_RULES]],
        'pertes.constat' => ['params' => ['id' => 'id'], 'required' => ['id']],
        'pertes.regles' => [],
        'pertes.reglages' => [],
        'pertes.exercices' => [],
        'pertes.pieces' => [],
        'pertes.installation' => [],
        'pertes.boitiers' => [],
        // Assistance (`docs/assistance.md`) : `assistance` et `assistance-reglages` sont les codes déjà émis par « Ma journée » et par le
        // cheminement, gardés tels quels ; une demande s'ouvre par `assistance.demande`
        'assistance' => ['params' => ['status' => self::TICKET_STATUSES, 'overdue' => 'flag', 'awaiting' => 'flag']],
        'assistance.demande' => ['params' => ['id' => 'id'], 'required' => ['id']],
        'assistance-reglages' => [],
        // Rapports PDF de la gestion (lot DOC) : la liste, ou la liste ouverte sur un rapport
        'rapports' => ['params' => ['id' => 'id']],
    ];

    /**
     * Paramètres d'un lien, contrôlés et rédigés : un paramètre inconnu ou mal formé est écarté ; null si le code d'écran n'est pas dans
     * la liste ou si un paramètre obligatoire manque.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>|null
     */
    public static function query(string $screen, array $params): ?array
    {
        $link = self::LINKS[$screen] ?? null;
        if ($link === null) {
            return null;
        }
        $out = [];
        foreach ($link['params'] ?? [] as $name => $shape) {
            $value = self::value($shape, $params[$name] ?? null);
            if ($value !== null) {
                $out[$name] = $value;
            }
        }
        foreach ($link['required'] ?? [] as $name) {
            if (!isset($out[$name])) {
                return null;
            }
        }

        return $out;
    }

    /** @param string|list<string>|array{many: list<string>} $shape */
    private static function value(string|array $shape, mixed $given): ?string
    {
        if ($given === null) {
            return null;
        }
        if ($shape === 'id') {
            return (is_int($given) || (is_string($given) && ctype_digit($given))) && (int) $given > 0 && (int) $given <= 2147483647 ? (string) (int) $given : null;
        }
        if ($shape === 'flag') {
            return in_array($given, [true, 1, '1'], true) ? '1' : null;
        }
        if (is_array($shape) && isset($shape['many'])) {
            $asked = is_array($given) ? $given : explode(',', (string) $given);
            $kept = array_values(array_filter($shape['many'], fn (string $v) => in_array($v, $asked, true)));

            return $kept !== [] ? implode(',', $kept) : null;
        }

        return is_array($shape) && is_string($given) && in_array($given, $shape, true) ? $given : null;
    }
}
