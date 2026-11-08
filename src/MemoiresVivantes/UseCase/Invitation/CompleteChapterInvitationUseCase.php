<?php

namespace App\MemoiresVivantes\UseCase\Invitation;

use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Services\ChapterInvitationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Uid\Uuid;

/**
 * POST /chapters/{id}/invitation/complete : la personne a terminé depuis la page partagée (08/11/2026). Sans
 * invitation (lien copié à la main) ou invitation déjà fermée, la réponse est la même, `applied` à false.
 */
class CompleteChapterInvitationUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ChapterInvitationService $invitations)
    {
    }

    /** @return array{status: string, applied: bool} */
    public function execute(Chapter $chapter, ?string $contributorId): array
    {
        $contributor = null;
        if ($contributorId !== null) {
            try {
                $contributor = $this->emProvider->getEntityManager()->getRepository(Contributor::class)->find(Uuid::fromString($contributorId));
            } catch (\Throwable) {
                $contributor = null;
            }
        }

        return ['status' => 'completed', 'applied' => $this->invitations->complete($chapter, $contributor) > 0];
    }
}
