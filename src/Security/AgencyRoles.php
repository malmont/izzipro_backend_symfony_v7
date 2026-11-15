<?php

namespace App\Security;

/**
 * Rôles d'agence (lot AG-1, 15/11/2026, `docs/agences-et-import.md` § 3.3). Ils se portent sur des comptes du SITE D'ATTACHE de
 * l'agence, et sont donnés ou retirés par le super administrateur SEULEMENT (`AgencyMemberService`) : ils ne sont volontairement ni
 * dans `TeamRoles::ALL` ni dans `RoleAssignmentPolicy::ASSIGNABLE`, donc un administrateur de site ne peut ni les donner ni les
 * retirer (l'écran des utilisateurs conserve un rôle qu'il ne connaît pas).
 *
 * Un compte qui ne porte qu'un rôle d'agence est un compte d'équipe, pas un client ; il n'atteint que `/api/agency`
 * (`AgencyScopeSubscriber`). L'agence d'une requête se lit toujours du site sur lequel le compte est connecté, jamais d'un paramètre.
 */
final class AgencyRoles
{
    /** Responsable de l'agence */
    public const MANAGER = 'ROLE_AGENCY';
    /** Collaborateur de l'agence */
    public const MEMBER = 'ROLE_AGENCY_MEMBER';
    public const ALL = [self::MANAGER, self::MEMBER];
    /** Nom du rôle tel que les routes le disent */
    public const NAMES = ['manager' => self::MANAGER, 'member' => self::MEMBER];

    /** @param list<string> $roles */
    public static function has(array $roles): bool
    {
        return array_intersect(self::ALL, $roles) !== [];
    }

    /** @param list<string> $roles @return string|null `manager`, `member`, ou null */
    public static function nameOf(array $roles): ?string
    {
        return in_array(self::MANAGER, $roles, true) ? 'manager' : (in_array(self::MEMBER, $roles, true) ? 'member' : null);
    }
}
