<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Book;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;

class GetBooksByUserUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(User $user): array
    {
        $em = $this->emProvider->getEntityManager();
        $repository = $em->getRepository(Book::class);

        return $repository->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }
}
