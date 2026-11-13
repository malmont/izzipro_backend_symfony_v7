<?php

namespace App\ESG\Security\Voter;

use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class DiagnosticSessionVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const SUBMIT = 'SUBMIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::SUBMIT])
            && $subject instanceof DiagnosticSession;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        // Équipe de la Boussole (lot BOUSSOLE-CONSEILLER, 13/11/2026) : conseiller, administrateur du site ou super administrateur,
        // sur un compte de la base commune ; lecture de tous les diagnostics du site, jamais la modification ni la soumission
        if (BoussoleTeamVoter::isTeam($user)) {
            return $attribute === self::VIEW;
        }
        if (!$user instanceof EsgUser) {
            return false;
        }

        /** @var DiagnosticSession $session */
        $session = $subject;

        // Same rule as ListSessionsUseCase: sessions of the user's own company,
        // whatever the role, so every listed session is readable.
        $userCompany = $user->getCompany();
        $sessionCompany = $session->getCompany();
        if ($userCompany && $sessionCompany && $userCompany->getId() === $sessionCompany->getId()) {
            return true;
        }

        // ROLE_ADMIN has full access
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // ROLE_CONSULTANT retiré (lot BOUSSOLE-CONSEILLER) : jamais posé sur aucun site ; l'équipe passe par ROLE_BOUSSOLE_CONSEILLER

        return false;
    }
}
