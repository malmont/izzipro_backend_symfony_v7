<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Chapter;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Uid\Uuid;

class GetChapterUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(string $id): ?Chapter
    {
        $em = $this->emProvider->getEntityManager();
        $repository = $em->getRepository(Chapter::class);

        return $repository->find(Uuid::fromString($id));
    }
}
