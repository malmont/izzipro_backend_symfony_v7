<?php

namespace App\MemoiresVivantes\UseCase\Invitation;

use App\MemoiresVivantes\Dto\ChapterInvitationOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\ChapterInvitation;
use App\MemoiresVivantes\Repository\ChapterInvitationRepository;
use App\Services\TenantEntityManagerProvider;

/** GET /books/{id}/invitations : suivi des invitations du livre, les plus récentes d'abord (08/11/2026) */
class ListBookInvitationsUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return list<array<string, mixed>> */
    public function execute(Book $book): array
    {
        /** @var ChapterInvitationRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(ChapterInvitation::class);

        return array_map([ChapterInvitationOutputDto::class, 'toArray'], $repository->findByBook($book));
    }
}
