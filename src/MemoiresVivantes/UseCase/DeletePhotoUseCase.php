<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\ChapterPhoto;
use App\Services\TenantEntityManagerProvider;

class DeletePhotoUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly string $projectDir
    ) {}

    public function execute(ChapterPhoto $photo): void
    {
        $em = $this->emProvider->getEntityManager();
        
        $file = $this->projectDir . '/public/uploads/memoires/' . $photo->getFilePath();
        if (file_exists($file)) {
            unlink($file);
        }

        $em->remove($photo);
        $em->flush();
    }
}
