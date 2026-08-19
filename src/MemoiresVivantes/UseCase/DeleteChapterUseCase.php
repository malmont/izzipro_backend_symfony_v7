<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Chapter;
use App\Services\TenantEntityManagerProvider;

class DeleteChapterUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly string $projectDir
    ) {}

    public function execute(Chapter $chapter): void
    {
        $em = $this->emProvider->getEntityManager();
        
        $uploadDir = $this->projectDir . '/public/uploads/memoires/';
        
        foreach ($chapter->getPhotos() as $photo) {
            $file = $uploadDir . $photo->getFilePath();
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $em->remove($chapter);
        $em->flush();
    }
}
