<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Repository\ChapterRepository;
use App\Services\TenantEntityManagerProvider;

class GetChaptersByBookUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * Récupère la liste des chapitres appartenant à un livre avec leurs photos pré-chargées.
     *
     * @param Book $book
     * @return array
     */
    public function execute(Book $book): array
    {
        $em = $this->emProvider->getEntityManager();
        /** @var ChapterRepository $repo */
        $repo = $em->getRepository(Chapter::class);

        return $repo->findByBookWithPhotos($book);
    }
}
