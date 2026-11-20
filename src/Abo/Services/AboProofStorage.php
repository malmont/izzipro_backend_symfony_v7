<?php

namespace App\Abo\Services;

use App\Services\TenantConnectionProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos de preuve d'un « fait » (lot ABO-3a, 20/11/2026, décision 9 du propriétaire) : stockage PRIVÉ du site
 * (`var/storage/private_media/abo-proofs/<site>/<année>/<aléa>.jpg`), jamais d'adresse publique ; servies à l'administrateur du site et à
 * l'abonné seulement ; gardées 12 mois (`AboDeliveryService::purgeProofs`). L'image est relue puis RÉENCODÉE en JPEG par GD : AUCUNE
 * métadonnée n'est gardée (position GPS, appareil, date) ; l'orientation de l'appareil est appliquée aux pixels avant (sinon la photo d'un
 * téléphone sortirait couchée) ; 2000 pixels au plus de côté. Jamais envoyée à l'IA.
 */
class AboProofStorage
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_SIDE_IN = 12000;
    public const MAX_SIDE_OUT = 2000;
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir, private readonly TenantConnectionProvider $tenant)
    {
    }

    /** Le refus d'une photo, ou null : `PROOF_TOO_LARGE`, `PROOF_FORMAT` (JPEG, PNG ou WebP ; un HEIC d'iPhone se convertit en JPEG avant l'envoi) */
    public static function refusal(UploadedFile $file): ?string
    {
        if (!$file->isValid() || (int) $file->getSize() > self::MAX_BYTES) {
            return 'PROOF_TOO_LARGE';
        }
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '');
        $size = @getimagesize($file->getPathname());
        if (!in_array($mime, self::MIMES, true) || $size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE_IN || $size[1] > self::MAX_SIDE_IN) {
            return 'PROOF_FORMAT';
        }

        return null;
    }

    /**
     * Réencode et range la photo.
     *
     * @return array{path: string, sha256: string, mime: string}|null null si l'image ne se relit pas
     */
    public function store(UploadedFile $file): ?array
    {
        $raw = (string) file_get_contents($file->getPathname());
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return null;
        }
        $image = self::orient($image, self::exifOrientation($raw));
        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = min(1.0, self::MAX_SIDE_OUT / max($w, $h));
        [$tw, $th] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        // une toile neuve, fond blanc (la transparence d'un PNG ne sort pas noire) : seuls les pixels sont recopiés
        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($image);
        $relative = gmdate('Y') . '/' . bin2hex(random_bytes(16)) . '.jpg';
        $path = $this->root() . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            imagedestroy($out);

            return null;
        }
        $ok = imagejpeg($out, $path, 85);
        imagedestroy($out);
        if (!$ok || !is_file($path)) {
            return null;
        }

        return ['path' => $relative, 'sha256' => (string) hash_file('sha256', $path), 'mime' => 'image/jpeg'];
    }

    /** Le fichier d'une preuve, ou null s'il n'existe plus ; jamais hors du dossier des preuves du site */
    public function file(?string $relative): ?string
    {
        if ($relative === null || !preg_match('#^\d{4}/[0-9a-f]{32}\.jpg$#', $relative)) {
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
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenant->getTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/abo-proofs/' . $code;
    }

    /** L'orientation EXIF (1 à 8) d'un JPEG, lue à la main (l'extension exif n'est pas chargée) ; 1 si absente ou illisible */
    public static function exifOrientation(string $jpeg): int
    {
        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $len = strlen($jpeg);
        while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            $size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, max(0, $size - 8));
                $little = substr($tiff, 0, 2) === 'II';
                $u16 = fn (int $o) => strlen($tiff) >= $o + 2 ? unpack($little ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
                $u32 = fn (int $o) => strlen($tiff) >= $o + 4 ? unpack($little ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
                $ifd = $u32(4);
                $count = $u16($ifd);
                for ($i = 0; $i < $count && $i < 200; ++$i) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($u16($entry) === 0x0112) {
                        $value = $u16($entry + 8);

                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }

                return 1;
            }
            if ($marker === 0xDA || $size < 2) {
                break; // début de l'image : plus d'en-tête après
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            6, 7 => 270, // imagerotate tourne dans le sens inverse des aiguilles : 270 = un quart de tour dans le sens des aiguilles
            5, 8 => 90,  // 5 (transposée) = miroir puis un quart de tour à gauche ; 7 (transverse) = miroir puis un quart de tour à droite
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
