<?php

namespace App\MemoiresVivantes\UseCase\Invitation;

use App\MemoiresVivantes\Dto\ChapterInvitationOutputDto;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Exception\ChapterInvitationException;
use App\MemoiresVivantes\Services\ChapterInvitationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** POST /chapters/{id}/invitations : corps {email, contributorId?, locale?} (08/11/2026) */
class CreateChapterInvitationUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ChapterInvitationService $invitations)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function execute(Chapter $chapter, array $data, Request $request): array
    {
        $contributor = null;
        $contributorId = $data['contributorId'] ?? null;
        if ($contributorId !== null && $contributorId !== '') {
            try {
                $contributor = $this->emProvider->getEntityManager()->getRepository(Contributor::class)->find(Uuid::fromString((string) $contributorId));
            } catch (\Throwable) {
                $contributor = null;
            }
            if ($contributor === null) {
                throw new ChapterInvitationException(404, 'CONTRIBUTOR_NOT_FOUND', 'Ce contributeur ne fait pas partie du livre.');
            }
        }

        $invitation = $this->invitations->create($chapter, $contributor, (string) ($data['email'] ?? ''), $data['locale'] ?? null, $request);

        return ChapterInvitationOutputDto::toArray($invitation);
    }
}
