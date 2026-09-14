<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterPhoto;
use App\MemoiresVivantes\Services\ChapterService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AddChapterPhotoUseCase
{
    public function __construct(
        private readonly ChapterService $chapterService
    ) {}

    /**
     * Ajoute une photo à un chapitre via ChapterService.
     *
     * @param Chapter $chapter
     * @param UploadedFile $file
     * @param array $data
     * @return ChapterPhoto
     */
    public function execute(Chapter $chapter, UploadedFile $file, array $data = []): ChapterPhoto
    {
        return $this->chapterService->addPhoto($chapter, $file, $data);
    }
}
