<?php

namespace App\MemoiresVivantes\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Valide une image envoyée par un utilisateur (photos de chapitre, couverture de livre).
 *
 * Le type est déterminé par le contenu du fichier, jamais par son nom ou le type annoncé par le client :
 * sans cette liste, un fichier HTML/SVG serait enregistré tel quel et servi par nginx depuis le domaine
 * du backend (XSS stockée).
 */
final class ImageUploadValidator
{
    /** Type réel => extension enregistrée */
    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @return array{extension: string, mimeType: string, size: int}
     *
     * @throws \InvalidArgumentException message destiné à l'utilisateur
     */
    public static function validate(UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_TYPES[$mimeType])) {
            throw new \InvalidArgumentException('Format d\'image non accepté : utilisez une image JPEG, PNG, WebP ou GIF.');
        }

        $size = $file->getSize();
        if ($size === false || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image trop volumineuse (20 Mo maximum).');
        }

        return ['extension' => self::ALLOWED_TYPES[$mimeType], 'mimeType' => $mimeType, 'size' => $size];
    }

    /** Nom de fichier imprévisible avec l'extension correspondant au type réel */
    public static function randomFilename(string $extension, string $suffix = ''): string
    {
        return bin2hex(random_bytes(12)) . $suffix . '.' . $extension;
    }
}
