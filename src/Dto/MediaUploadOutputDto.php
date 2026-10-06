<?php

namespace App\Dto;

use App\Entity\SharedMedia;

/** Réponse de POST /api/media : média privé de la médiathèque, utilisable par sa clé (/media/secure/{clé}) */
final class MediaUploadOutputDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $url,
        public readonly string $type,
        public readonly ?string $mimeType,
        public readonly ?int $size,
        public readonly string $title,
        public readonly ?string $scrollStatus
    ) {
    }

    public static function fromEntity(SharedMedia $media, string $host): self
    {
        return new self(
            (int) $media->getId(),
            (string) $media->getAccessKey(),
            rtrim($host, '/') . '/media/secure/' . $media->getAccessKey(),
            $media->getMediaType(),
            $media->getMimeType(),
            $media->getFileSize(),
            (string) $media->getTitre(),
            $media->getScrollStatus()
        );
    }
}
