<?php

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Attribut `MEMOIRES_TEAM` (13/11/2026) : l'utilisateur travaille pour Mémoires Vivantes, administrateur du site ou
 * biographe (TeamRoles::MEMOIRES_ACCESS). Pour `#[IsGranted('MEMOIRES_TEAM')]` sur les routes ouvertes à l'équipe.
 */
final class MemoiresTeamVoter extends Voter
{
    public const ATTRIBUTE = 'MEMOIRES_TEAM';

    /** Voit tout Mémoires Vivantes (14/11/2026) : administrateur du site ou responsable des biographes (TeamRoles::MEMOIRES_MANAGE) */
    public const MANAGE = 'MEMOIRES_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::ATTRIBUTE, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof UserInterface && ($attribute === self::MANAGE ? TeamRoles::managesMemoires($user) : TeamRoles::opensMemoires($user));
    }
}
