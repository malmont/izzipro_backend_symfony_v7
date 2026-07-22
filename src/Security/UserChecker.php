<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            // Vous pouvez personnaliser le message d'erreur
            throw new CustomUserMessageAccountStatusException('Your account is not verified. Please check your email.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Vous pouvez effectuer d'autres vérifications après l'authentification si besoin
    }
}
