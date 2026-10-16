<?php

namespace App\MemoiresVivantes\UseCase;

use App\Entity\User;
use App\MemoiresVivantes\BookType\BookTypeResolver;
use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Exception\InvalidBookTypeException;

/**
 * Création d'un livre depuis l'application mobile Living Memory (16/10/2026) : même corps que POST /books, livre
 * marqué channel = mobile (jamais modifié ensuite). Seule différence : un type demandé mais inconnu ou désactivé est
 * refusé (InvalidBookTypeException → 422 TYPE_INVALID) au lieu d'être remplacé par un livre individuel ; sans type,
 * le livre est individuel comme sur le web.
 */
class CreateMobileBookUseCase
{
    public function __construct(
        private readonly CreateBookUseCase $createBookUseCase,
        private readonly BookTypeResolver $bookTypeResolver
    ) {}

    public function execute(User $user, BookInputDto $dto): Book
    {
        $requested = trim((string) $dto->typeInput);
        if ($requested !== '' && ($dto->type === null || !$this->bookTypeResolver->isSelectable($dto->type))) {
            throw new InvalidBookTypeException($requested);
        }

        return $this->createBookUseCase->execute($user, $dto, Book::CHANNEL_MOBILE);
    }
}
