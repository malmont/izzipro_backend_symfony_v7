<?php

namespace App\Dto;

use App\Entity\SharedMedia;

/**
 * Média de la médiathèque tel que le sélecteur de l'éditeur des landing pages le reçoit (GET /api/media). Un média
 * privé a une clé (key) et s'emploie par elle ; un média public (téléversé public dans l'administration) n'a pas de
 * clé : son url est l'adresse directe du fichier.
 */
final class MediaItemOutputDto
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $key,
        public readonly string $url,
        public readonly string $type,
        public readonly ?string $mimeType,
        public readonly ?int $size,
        public readonly string $title,
        public readonly string $createdAt,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?float $duration,
        public readonly ?string $scrollStatus,
        public readonly string $visibility
    ) {
    }

    /** @param array{0: ?int, 1: ?int} $dimensions largeur et hauteur d'une image, si elles se lisent */
    public static function fromEntity(SharedMedia $media, string $url, array $dimensions = [null, null]): self
    {
        return new self(
            (int) $media->getId(),
            $media->isPrivate() ? $media->getAccessKey() : null,
            $url,
            $media->getMediaType(),
            $media->getMimeType(),
            $media->getFileSize(),
            (string) $media->getTitre(),
            $media->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $dimensions[0],
            $dimensions[1],
            null,
            $media->getScrollStatus(),
            $media->getVisibility()
        );
    }
}
