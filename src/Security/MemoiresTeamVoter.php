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

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof UserInterface && TeamRoles::opensMemoires($user);
    }
}
