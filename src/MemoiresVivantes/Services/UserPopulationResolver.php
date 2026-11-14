<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\Security\TeamRoles;

/**
 * Population d'un compte pour la console d'administration (16/10/2026). Rien en base ne la porte : elle est calculée
 * avec une priorité. team : biographe de l'équipe (ROLE_MEMOIRES_BIOGRAPHE, depuis le 13/11/2026 ; avant : tout
 * ROLE_ADMIN). admin : administrateur du site sans ce rôle (boutique, caisse, gestion). mobile : inscrit depuis
 * l'application Living Memory (signup_channel) ou propriétaire d'au moins un livre channel = mobile. web_client : tous
 * les autres (clients dont un biographe fait le livre, collaborateurs invités, comptes de la boutique). Un client web
 * qui crée un livre dans l'application devient donc « mobile ».
 */
final class UserPopulationResolver
{
    public const TEAM = 'team';
    public const ADMIN = 'admin';
    public const WEB_CLIENT = 'web_client';
    public const MOBILE = 'mobile';
    public const ALL = [self::TEAM, self::ADMIN, self::MOBILE, self::WEB_CLIENT];

    public static function isTeam(User $user): bool
    {
        return TeamRoles::hasAny($user->getRoles(), [TeamRoles::MEMOIRES_BIOGRAPHE, TeamRoles::MEMOIRES_RESPONSABLE]); // biographe ou responsable des biographes (14/11/2026)
    }

    public static function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    public static function resolve(User $user, int $mobileBooks): string
    {
        if (self::isTeam($user)) {
            return self::TEAM;
        }
        if (self::isAdmin($user)) {
            return self::ADMIN;
        }
        if ($user->getSignupChannel() === User::SIGNUP_MOBILE || $mobileBooks > 0) {
            return self::MOBILE;
        }

        return self::WEB_CLIENT;
    }
}
