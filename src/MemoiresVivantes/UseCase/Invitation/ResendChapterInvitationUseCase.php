<?php

namespace App\MemoiresVivantes\UseCase\Invitation;

use App\MemoiresVivantes\Dto\ChapterInvitationOutputDto;
use App\MemoiresVivantes\Entity\ChapterInvitation;
use App\MemoiresVivantes\Services\ChapterInvitationService;
use Symfony\Component\HttpFoundation\Request;

/** POST /invitations/{id}/resend : nouveau lien tout de suite (08/11/2026) */
class ResendChapterInvitationUseCase
{
    public function __construct(private readonly ChapterInvitationService $invitations)
    {
    }

    /** @return array<string, mixed> */
    public function execute(ChapterInvitation $invitation, Request $request): array
    {
        return ChapterInvitationOutputDto::toArray($this->invitations->resend($invitation, $request));
    }
}
