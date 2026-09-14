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
        try {
            $uuid = Uuid::fromString($id);
            $em = $this->emProvider->getEntityManager();
            return $em->getRepository(Chapter::class)->find($uuid);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
