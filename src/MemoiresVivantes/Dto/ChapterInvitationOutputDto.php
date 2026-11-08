<?php

namespace App\MemoiresVivantes\Dto;

use App\MemoiresVivantes\Entity\ChapterInvitation;

/** Invitation par e-mail d'un chapitre telle que le frontend la lit (08/11/2026) ; le lien signé n'y figure jamais */
final class ChapterInvitationOutputDto
{
    /** @return array<string, mixed> */
    public static function toArray(ChapterInvitation $invitation): array
    {
        $chapter = $invitation->getChapter();
        $contributor = $invitation->getContributor();

        return [
            'id' => (string) $invitation->getId(),
            'bookId' => (string) $chapter?->getBook()?->getId(),
            'chapterId' => (string) $chapter?->getId(),
            'chapterTitle' => $chapter?->getTitle(),
            'contributorId' => $contributor === null ? null : (string) $contributor->getId(),
            'contributorFirstName' => $contributor?->getFirstName(),
            'email' => $invitation->getEmail(),
            'locale' => $invitation->getLocale(),
            'status' => $invitation->getStatus(),
            'firstSentAt' => $invitation->getFirstSentAt()->format(\DateTimeInterface::ATOM),
            'lastSentAt' => $invitation->getLastSentAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'renewalsUsed' => $invitation->getRenewalsUsed(),
            'renewalsMax' => ChapterInvitation::RENEWALS_MAX,
            'completedAt' => $invitation->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
