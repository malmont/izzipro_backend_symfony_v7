<?php

namespace App\MemoiresVivantes\Security;

use App\MemoiresVivantes\Entity\Book;
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

        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        /** @var Book $book */
        $book = $subject;

        return $book->getUser()->getUserIdentifier() === $user->getUserIdentifier();
    }
}
