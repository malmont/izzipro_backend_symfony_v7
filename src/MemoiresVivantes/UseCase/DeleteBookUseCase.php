<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Book;
use App\Services\TenantEntityManagerProvider;

class DeleteBookUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly string $projectDir
    ) {}

    public function execute(Book $book): void
    {
        $em = $this->emProvider->getEntityManager();
        
        $uploadDir = $this->projectDir . '/public/uploads/memoires/';
        
        // Supprimer la cover
        if ($book->getCoverPhotoPath()) {
            $file = $uploadDir . $book->getCoverPhotoPath();
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Supprimer toutes les photos de chapitres et les entités chapitres
        foreach ($book->getChapters() as $chapter) {
            foreach ($chapter->getPhotos() as $photo) {
                $file = $uploadDir . $photo->getFilePath();
                if (file_exists($file)) {
                    unlink($file);
                }
                $em->remove($photo);
            }
            $em->remove($chapter);
        }

        $em->remove($book);
        $em->flush();
    }
}
