<?php

namespace App\MemoiresVivantes\Security;

use App\MemoiresVivantes\Entity\Chapter;
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

        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        /** @var Chapter $chapter */
        $chapter = $subject;
        $book = $chapter->getBook();

        return $book->getUser()->getUserIdentifier() === $user->getUserIdentifier();
    }
}
