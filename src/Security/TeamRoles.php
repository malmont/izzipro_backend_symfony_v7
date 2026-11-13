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
    /** Mémoires Vivantes : biographe de l'équipe (livres de l'entreprise, rendez-vous, console Équipe) */
    public const MEMOIRES_BIOGRAPHE = 'ROLE_MEMOIRES_BIOGRAPHE';

    /** Boussole ESG : conseiller de l'équipe (lecture des entreprises du site, de leurs diagnostics, rapports et documents) */
    public const BOUSSOLE_CONSEILLER = 'ROLE_BOUSSOLE_CONSEILLER';

    /** Libellé (EasyAdmin) => rôle, pour toutes les applications */
    public const ALL = [
        'Biographe (Mémoires Vivantes)' => self::MEMOIRES_BIOGRAPHE,
        'Conseiller (Boussole ESG)' => self::BOUSSOLE_CONSEILLER,
    ];

    /** Rôle d'accès à l'API garanti à tout membre d'une équipe */
    public const COMPANION = 'ROLE_USER_INTERNET';

    /** Ressource du journal content_audit_log */
    public const AUDIT_RESOURCE = 'user_team';

    /** Rôles qui ouvrent les droits d'équipe de Mémoires Vivantes : l'administrateur du site garde tout */
    public const MEMOIRES_ACCESS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', self::MEMOIRES_BIOGRAPHE];

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
