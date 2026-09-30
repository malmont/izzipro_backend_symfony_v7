<?php

namespace App\Security;

/**
 * Rôles qu'un administrateur peut attribuer à un utilisateur de son site (écran EasyAdmin des utilisateurs).
 *
 * ROLE_SUPER_ADMIN (propriétaire de la plateforme : synchronisation de la configuration commune à tous les sites)
 * n'est ni attribuable ni retirable par un administrateur de site : seul un super administrateur le peut, sinon il
 * s'ajoute en SQL. Jusqu'au 30/09/2026, les rôles étaient un champ libre : un admin de site pouvait se l'attribuer.
 */
final class RoleAssignmentPolicy
{
    public const SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /** Rôles proposés à un administrateur de site : libellé => rôle */
    public const ASSIGNABLE = [
        'Administrateur du site' => 'ROLE_ADMIN',
        'Client en ligne' => 'ROLE_USER_INTERNET',
        'Caisse (point de vente)' => 'ROLE_USER_POS',
        'Utilisateur' => 'ROLE_USER',
    ];

    /** @return array<string, string> choix du formulaire */
    public function choices(bool $editorIsSuperAdmin): array
    {
        return $editorIsSuperAdmin
            ? self::ASSIGNABLE + ['Propriétaire de la plateforme' => self::SUPER_ADMIN]
            : self::ASSIGNABLE;
    }

    /**
     * Rôles à enregistrer : seuls les rôles connus sont acceptés ; ROLE_SUPER_ADMIN ne change que si l'éditeur est
     * lui-même super administrateur ; les rôles inconnus déjà présents sont conservés (rien n'est perdu en silence).
     *
     * @param list<string> $requested rôles demandés par le formulaire
     * @param list<string> $previous rôles avant modification (vide à la création)
     * @return list<string>
     */
    public function apply(array $requested, array $previous, bool $editorIsSuperAdmin): array
    {
        $known = array_values(self::ASSIGNABLE);
        $roles = array_values(array_intersect($requested, $known));

        $hadSuperAdmin = in_array(self::SUPER_ADMIN, $previous, true);
        $wantsSuperAdmin = in_array(self::SUPER_ADMIN, $requested, true);
        if ($editorIsSuperAdmin ? $wantsSuperAdmin : $hadSuperAdmin) {
            $roles[] = self::SUPER_ADMIN;
        }

        $unknownPrevious = array_diff($previous, $known, [self::SUPER_ADMIN]);

        return array_values(array_unique([...$roles, ...$unknownPrevious]));
    }
}
