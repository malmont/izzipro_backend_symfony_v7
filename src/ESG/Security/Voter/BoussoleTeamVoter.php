<?php

namespace App\ESG\Security\Voter;

use App\Entity\User;
use App\Security\TeamRoles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `BOUSSOLE_TEAM` (lot BOUSSOLE-CONSEILLER, 13/11/2026) : l'utilisateur travaille pour la Boussole ESG du site, conseiller
 * (`ROLE_BOUSSOLE_CONSEILLER`), administrateur du site ou super administrateur (`TeamRoles::BOUSSOLE_ACCESS`). C'est toujours un compte
 * de la base commune (`user`) : un compte d'entreprise de la Boussole (`esg_user`) n'est jamais de l'équipe, quels que soient ses rôles.
 */
final class BoussoleTeamVoter extends Voter
{
    public const ATTRIBUTE = 'BOUSSOLE_TEAM';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return self::isTeam($token->getUser());
    }

    public static function isTeam(mixed $user): bool
    {
        return $user instanceof User && TeamRoles::opensBoussole($user);
    }
}
