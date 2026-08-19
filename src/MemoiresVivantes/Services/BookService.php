<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BookService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly string $projectDir
    ) {}

    public function updateCover(Book $book, UploadedFile $file): string
    {
        $em = $this->emProvider->getEntityManager();
        
        $uploadDir = $this->projectDir . '/public/uploads/memoires/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        if ($book->getCoverPhotoPath()) {
            $oldFile = $uploadDir . $book->getCoverPhotoPath();
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $newFilename = uniqid() . '_cover.' . $file->guessExtension();
        $file->move($uploadDir, $newFilename);

        $book->setCoverPhotoPath($newFilename);
        $em->flush();

        return $newFilename;
    }

    public function removeCover(Book $book): void
    {
        $em = $this->emProvider->getEntityManager();
        if ($book->getCoverPhotoPath()) {
            $file = $this->projectDir . '/public/uploads/memoires/' . $book->getCoverPhotoPath();
            if (file_exists($file)) {
                unlink($file);
            }
            $book->setCoverPhotoPath(null);
            $em->flush();
        }
    }
}
