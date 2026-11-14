<?php

namespace App\MemoiresVivantes\Security;

use App\MemoiresVivantes\Entity\Book;
use App\Security\TeamRoles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class BookVoter extends Voter
{
    public const VIEW = 'BOOK_VIEW';
    public const EDIT = 'BOOK_EDIT';
    public const DELETE = 'BOOK_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Book;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }

        return self::canAccess($user, $subject);
    }

    /**
     * La règle d'accès à un livre (14/11/2026), commune aux livres et à leurs chapitres : l'administrateur du site et le responsable
     * des biographes ont tous les livres ; un biographe a SES livres, ceux qu'il possède et ceux dont il est le biographe affecté ;
     * tout autre compte a les livres qu'il possède.
     */
    public static function canAccess(UserInterface $user, Book $book): bool
    {
        if (TeamRoles::managesMemoires($user)) {
            return true;
        }
        if ($book->getUser()->getUserIdentifier() === $user->getUserIdentifier()) {
            return true;
        }

        return in_array(TeamRoles::MEMOIRES_BIOGRAPHE, $user->getRoles(), true) && $book->getBiographer()?->getUserIdentifier() === $user->getUserIdentifier();
    }
}
