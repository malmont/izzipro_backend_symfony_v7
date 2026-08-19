<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Entity\ChapterPhoto;
use App\Services\TenantEntityManagerProvider;

class UpdatePhotoUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(ChapterPhoto $photo, array $data): ChapterPhoto
    {
        $em = $this->emProvider->getEntityManager();

        if (isset($data['orientation'])) $photo->setOrientation($data['orientation']);
        if (isset($data['paragraphPosition'])) $photo->setParagraphPosition((int)$data['paragraphPosition']);
        if (isset($data['sortOrder'])) $photo->setSortOrder((int)$data['sortOrder']);

        $em->flush();

        return $photo;
    }
}
