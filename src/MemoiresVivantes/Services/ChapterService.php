<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterPhoto;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ChapterService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly string $projectDir
    ) {}

    public function addPhoto(Chapter $chapter, UploadedFile $file, array $data = []): ChapterPhoto
    {
        $em = $this->emProvider->getEntityManager();
        
        $uploadDir = $this->projectDir . '/public/uploads/memoires/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $newFilename = uniqid() . '.' . $file->guessExtension();
        $fileSize = $file->getSize();
        $mimeType = $file->getMimeType() ?? 'image/jpeg';
        
        $file->move($uploadDir, $newFilename);

        $photo = new ChapterPhoto();
        $photo->setChapter($chapter);
        $photo->setFilePath($newFilename);
        $photo->setFileSize($fileSize);
        $photo->setMimeType($mimeType);
        $photo->setOrientation($data['orientation'] ?? 'portrait');
        $photo->setParagraphPosition(isset($data['paragraphPosition']) ? (int)$data['paragraphPosition'] : null);
        $photo->setSortOrder(isset($data['sortOrder']) ? (int)$data['sortOrder'] : 0);

        $em->persist($photo);
        $em->flush();

        return $photo;
    }
}
