<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Book;

class GetChaptersByBookUseCase
{
    /**
     * Récupère la liste des chapitres appartenant à un livre.
     *
     * @param Book $book
     * @return array
     */
    public function execute(Book $book): array
    {
        return $book->getChapters()->toArray();
    }
}
