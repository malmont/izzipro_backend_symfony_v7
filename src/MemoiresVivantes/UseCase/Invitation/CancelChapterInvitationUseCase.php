<?php

namespace App\MemoiresVivantes\UseCase\Invitation;

use App\MemoiresVivantes\Dto\ChapterInvitationOutputDto;
use App\MemoiresVivantes\Entity\ChapterInvitation;
use App\MemoiresVivantes\Services\ChapterInvitationService;

/** DELETE /invitations/{id} : arrêt des envois (08/11/2026) */
class CancelChapterInvitationUseCase
{
    public function __construct(private readonly ChapterInvitationService $invitations)
    {
    }

    /** @return array<string, mixed> */
    public function execute(ChapterInvitation $invitation): array
    {
        return ChapterInvitationOutputDto::toArray($this->invitations->cancel($invitation));
    }
}
