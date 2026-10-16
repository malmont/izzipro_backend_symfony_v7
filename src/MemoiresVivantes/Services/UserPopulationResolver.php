<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;

/**
 * Population d'un compte pour la console d'administration (16/10/2026). Rien en base ne la porte : elle est calculée
 * avec une priorité. team : administrateur (biographes). mobile : inscrit depuis l'application Living Memory
 * (signup_channel) ou propriétaire d'au moins un livre channel = mobile. web_client : tous les autres (clients dont
 * un biographe fait le livre, collaborateurs invités, comptes de la boutique). Un client web qui crée un livre dans
 * l'application devient donc « mobile ».
 */
final class UserPopulationResolver
{
    public const TEAM = 'team';
    public const WEB_CLIENT = 'web_client';
    public const MOBILE = 'mobile';

    public static function isTeam(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    public static function resolve(User $user, int $mobileBooks): string
    {
        if (self::isTeam($user)) {
            return self::TEAM;
        }
        if ($user->getSignupChannel() === User::SIGNUP_MOBILE || $mobileBooks > 0) {
            return self::MOBILE;
        }

        return self::WEB_CLIENT;
    }
}
