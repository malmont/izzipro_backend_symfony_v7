<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Dto\BookInputDto;
use App\MemoiresVivantes\Entity\Book;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;

class CreateBookUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(User $user, BookInputDto $dto): Book
    {
        $em = $this->emProvider->getEntityManager();

        $book = new Book();
        $book->setUser($user);
        $book->setTitle($dto->title);
        $book->setSubtitle($dto->subtitle);
        $book->setBirthplace($dto->birthplace);
        $book->setFormat($dto->format ?? 'livre_s');
        $book->setType($dto->type ?? 'individuel');
        $book->setPerson1FirstName($dto->person1FirstName);
        $book->setPerson1Birthplace($dto->person1Birthplace);
        $book->setPerson2FirstName($dto->person2FirstName);
        $book->setPerson2Birthplace($dto->person2Birthplace);

        $em->persist($book);
        $em->flush();

        return $book;
    }
}
