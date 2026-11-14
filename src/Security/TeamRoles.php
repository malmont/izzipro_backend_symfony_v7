<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rôles d'équipe propres à chaque application (décision du propriétaire du 13/11/2026) : une seule base de comptes,
 * pas de comptes séparés ; chaque application reconnaît son équipe à SON rôle, posé en plus des rôles existants du
 * compte. ROLE_ADMIN reste l'administrateur du site (boutique, caisse, gestion, EasyAdmin) et garde tous ses droits.
 *
 * Convention commune à toutes les applications (fiche : docs/memoires-vivantes.md, « Équipe ») :
 *  - le rôle est un rôle d'accès pur, hors `role_hierarchy` ; il est déclaré ici (libellé => rôle) et nulle part ailleurs ;
 *  - un compte qui le reçoit garde au moins COMPANION (règle `^/api` de security.yaml) ;
 *  - toute attribution ou retrait passe par App\Services\TeamRoleService\TeamRoleService, qui écrit dans le journal
 *    du site (content_audit_log, ressource AUDIT_RESOURCE, action `grant` ou `revoke`) ;
 *  - les API renvoient `teamRoles` (liste des rôles d'équipe portés) sur chaque utilisateur.
 */
final class TeamRoles
{
    /**
     * Mémoires Vivantes : biographe de l'équipe. Depuis le 14/11/2026 il ne travaille que sur SES livres : ceux qu'il possède et ceux
     * dont il est le biographe affecté (leurs chapitres, séances, questionnaires, contributeurs). Il n'invite personne.
     */
    public const MEMOIRES_BIOGRAPHE = 'ROLE_MEMOIRES_BIOGRAPHE';

    /**
     * Mémoires Vivantes : responsable des biographes (14/11/2026). Tous les livres du site, l'équipe (inviter un biographe, donner ou
     * retirer le rôle de biographe, affecter un biographe à un livre), tous les rendez-vous. Rien d'autre du site, ni EasyAdmin.
     * Nommé par l'administrateur du site seulement.
     */
    public const MEMOIRES_RESPONSABLE = 'ROLE_MEMOIRES_RESPONSABLE';

    /** Boussole ESG : conseiller de l'équipe (lecture des entreprises du site, de leurs diagnostics, rapports et documents) */
    public const BOUSSOLE_CONSEILLER = 'ROLE_BOUSSOLE_CONSEILLER';

    /** Libellé (EasyAdmin) => rôle, pour toutes les applications */
    public const ALL = [
        'Biographe (Mémoires Vivantes)' => self::MEMOIRES_BIOGRAPHE,
        'Responsable des biographes (Mémoires Vivantes)' => self::MEMOIRES_RESPONSABLE,
        'Conseiller (Boussole ESG)' => self::BOUSSOLE_CONSEILLER,
    ];

    /** Refus fait à un compte client par Mémoires Vivantes web, l'outil de l'équipe (14/11/2026) : connexion, création d'un livre web, invitation d'un client */
    public const MEMOIRES_TEAM_ONLY = 'MEMOIRES_TEAM_ONLY';
    public const MEMOIRES_TEAM_ONLY_MESSAGE = 'Cet espace est réservé à l\'équipe. Vos récits se poursuivent dans l\'application Living Memory ou par le lien que vous avez reçu.';

    /** Code d'application de Mémoires Vivantes web (champ `app` de la connexion) : ce n'est pas une famille à membres, c'est l'outil de l'équipe */
    public const MEMOIRES_WEB_APP = 'memoires';

    /** Rôle d'accès à l'API garanti à tout membre d'une équipe */
    public const COMPANION = 'ROLE_USER_INTERNET';

    /** Ressource du journal content_audit_log */
    public const AUDIT_RESOURCE = 'user_team';

    /** Rôles de ceux qui travaillent pour Mémoires Vivantes (connexion au web, livres web, IA sans quota ni paiement) : l'administrateur du site garde tout */
    public const MEMOIRES_ACCESS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', self::MEMOIRES_RESPONSABLE, self::MEMOIRES_BIOGRAPHE];

    /** Rôles qui voient TOUT Mémoires Vivantes (tous les livres, l'équipe, tous les rendez-vous) : le biographe n'en est pas, il n'a que ses livres */
    public const MEMOIRES_MANAGE = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', self::MEMOIRES_RESPONSABLE];

    /** Rôles qui ouvrent les droits d'équipe de la Boussole ESG : l'administrateur du site garde tout */
    public const BOUSSOLE_ACCESS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', self::BOUSSOLE_CONSEILLER];

    public static function isTeamRole(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    /** @param list<string> $roles @return list<string> rôles d'équipe portés, dans l'ordre de ALL */
    public static function of(array $roles): array
    {
        return array_values(array_intersect(array_values(self::ALL), $roles));
    }

    /** @param list<string>|UserInterface $roles */
    public static function opensMemoires(array|UserInterface $roles): bool
    {
        return self::hasAny($roles instanceof UserInterface ? $roles->getRoles() : $roles, self::MEMOIRES_ACCESS);
    }

    /** @param list<string>|UserInterface $roles voit tout Mémoires Vivantes : administrateur du site ou responsable des biographes */
    public static function managesMemoires(array|UserInterface $roles): bool
    {
        return self::hasAny($roles instanceof UserInterface ? $roles->getRoles() : $roles, self::MEMOIRES_MANAGE);
    }

    /** @param list<string>|UserInterface $roles */
    public static function opensBoussole(array|UserInterface $roles): bool
    {
        return self::hasAny($roles instanceof UserInterface ? $roles->getRoles() : $roles, self::BOUSSOLE_ACCESS);
    }

    /** @param list<string> $roles @param list<string> $wanted */
    public static function hasAny(array $roles, array $wanted): bool
    {
        return array_intersect($wanted, $roles) !== [];
    }

    /** @param list<string> $roles @return list<string> rôles avec le rôle d'équipe et le rôle compagnon */
    public static function with(array $roles, string $role): array
    {
        return array_values(array_unique([...$roles, $role, self::COMPANION]));
    }

    /** @param list<string> $roles @return list<string> */
    public static function without(array $roles, string $role): array
    {
        return array_values(array_diff($roles, [$role]));
    }

    /** @param list<string> $roles @return list<string> un membre d'équipe garde le rôle compagnon, les autres comptes ne changent pas */
    public static function normalize(array $roles): array
    {
        return self::of($roles) === [] ? array_values($roles) : array_values(array_unique([...$roles, self::COMPANION]));
    }
}
