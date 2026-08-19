<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Entity\Book;
use App\Services\TenantEntityManagerProvider;

class UpdateBookUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(Book $book, BookInputDto $dto): Book
    {
        $em = $this->emProvider->getEntityManager();

        if ($dto->title) $book->setTitle($dto->title);
        if ($dto->subtitle !== null) $book->setSubtitle($dto->subtitle);
        if ($dto->birthplace !== null) $book->setBirthplace($dto->birthplace);
        if ($dto->format) $book->setFormat($dto->format);
        if ($dto->type) $book->setType($dto->type);
        if ($dto->person1FirstName !== null) $book->setPerson1FirstName($dto->person1FirstName);
        if ($dto->person1Birthplace !== null) $book->setPerson1Birthplace($dto->person1Birthplace);
        if ($dto->person2FirstName !== null) $book->setPerson2FirstName($dto->person2FirstName);
        if ($dto->person2Birthplace !== null) $book->setPerson2Birthplace($dto->person2Birthplace);

        $em->flush();

        return $book;
    }
}
