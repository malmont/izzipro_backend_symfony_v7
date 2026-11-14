<?php

namespace App\MemoiresVivantes\Security;

use App\MemoiresVivantes\Entity\Chapter;
use App\Security\TeamRoles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class ChapterVoter extends Voter
{
    public const VIEW = 'CHAPTER_VIEW';
    public const EDIT = 'CHAPTER_EDIT';
    public const DELETE = 'CHAPTER_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Chapter;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }

        /** @var Chapter $chapter */
        $chapter = $subject;

        return BookVoter::canAccess($user, $chapter->getBook()); // la règle du livre : tous, ses livres (possédés et affectés), ou les siens
    }
}
