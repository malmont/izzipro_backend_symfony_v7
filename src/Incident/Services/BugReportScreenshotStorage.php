<?php

namespace App\Incident\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Captures d'écran des signalements (lot INC-3, 18/11/2026) : stockage privé de la plateforme
 * (`var/storage/private_media/platform-bug-reports/<année>/<aléa>.<ext>`), jamais d'adresse publique, servies au seul super
 * administrateur. L'image est relue puis RÉENCODÉE (GD) : ses métadonnées (lieu, appareil, date) ne sont pas gardées. Une capture peut
 * montrer des données de clients (nom, panier, 4 derniers chiffres d'une carte), qu'aucun masque ne retire d'une image : elle n'est
 * jamais rendue au site ni au signaleur, et s'efface après la durée réglée.
 */
class BugReportScreenshotStorage
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 8000;
    public const MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** Le refus d'une capture, ou null : `TOO_LARGE`, `FORMAT` */
    public static function refusal(UploadedFile $file): ?string
    {
        if (!$file->isValid() || (int) $file->getSize() > self::MAX_BYTES) {
            return 'TOO_LARGE';
        }
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '');
        $size = @getimagesize($file->getPathname());
        if (!isset(self::MIMES[$mime]) || $size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            return 'FORMAT';
        }

        return null;
    }

    /** Réencode et range la capture ; rend son chemin relatif au stockage, ou null si l'image ne se relit pas */
    public function store(UploadedFile $file): ?string
    {
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '');
        $image = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if ($image === false || !isset(self::MIMES[$mime])) {
            return null;
        }
        $relative = gmdate('Y') . '/' . bin2hex(random_bytes(16)) . '.' . self::MIMES[$mime];
        $path = $this->root() . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            return null;
        }
        $ok = match ($mime) {
            'image/png' => imagepng($image, $path, 6),
            'image/jpeg' => imagejpeg($image, $path, 88),
            default => imagewebp($image, $path, 85),
        };
        imagedestroy($image);

        return $ok ? $relative : null;
    }

    /** Le fichier d'une capture, ou null s'il n'existe plus ; jamais hors du dossier des captures */
    public function file(?string $relative): ?string
    {
        if ($relative === null || !preg_match('#^\d{4}/[0-9a-f]{32}\.(png|jpg|webp)$#', $relative)) {
            return null;
        }
        $path = $this->root() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    public function remove(?string $relative): void
    {
        $path = $this->file($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function root(): string
    {
        return $this->projectDir . '/var/storage/private_media/platform-bug-reports';
    }
}
