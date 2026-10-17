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
        $bookId = $book->getId()->toRfc4122();
        $this->purgeContent($book);
        $em = $this->emProvider->getEntityManager();
        $em->remove($book);
        $em->flush();
        $this->removeGeneratedPdfs($bookId);
    }

    /**
     * Efface tout le contenu d'un livre (couverture, chapitres, photos, PDF générés) sans supprimer la ligne du livre :
     * suppression de compte (17/10/2026) quand des commandes d'impression ou des achats y sont rattachés.
     */
    public function purgeContent(Book $book): void
    {
        $em = $this->emProvider->getEntityManager();
        $uploadDir = $this->projectDir . '/var/storage/public_bucket/uploads/memoires/';

        // Supprimer la cover
        if ($book->getCoverPhotoPath()) {
            $file = $uploadDir . $book->getCoverPhotoPath();
            if (file_exists($file)) {
                unlink($file);
            }
            $book->setCoverPhotoPath(null);
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
        $em->flush();
        $this->removeGeneratedPdfs($book->getId()->toRfc4122());
    }

    /** PDF générés pour ce livre (intérieur, couverture) : ils restaient accessibles par leur URL après la suppression */
    private function removeGeneratedPdfs(string $bookId): void
    {
        $pdfDir = $this->projectDir . '/var/storage/public_bucket/uploads/memoires/books/' . $bookId;
        if (is_dir($pdfDir)) {
            foreach (glob($pdfDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($pdfDir);
        }
    }
}
