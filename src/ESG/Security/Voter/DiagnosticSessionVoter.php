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
        if (!$user instanceof EsgUser) {
            return false;
        }

        /** @var DiagnosticSession $session */
        $session = $subject;

        // ROLE_ADMIN has full access
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // ROLE_CONSULTANT access check
        if (in_array('ROLE_CONSULTANT', $user->getRoles(), true)) {
            if (method_exists($user, 'getAssignedCompanies')) {
                return $user->getAssignedCompanies()->contains($session->getCompany());
            }
            return true; // Default access if no relation defined
        }

        // ROLE_COMPANY access check
        if (in_array('ROLE_COMPANY', $user->getRoles(), true)) {
            $userCompany = $user->getCompany();
            $sessionCompany = $session->getCompany();

            if ($userCompany && $sessionCompany && $userCompany->getId() === $sessionCompany->getId()) {
                return true;
            }
        }

        return false;
    }
}
