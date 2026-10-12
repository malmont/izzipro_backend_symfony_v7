<?php

namespace App\Services\SiteContentService;

/**
 * Images du contenu d'exemple : dégradé vertical et formes (disques, anneau, grille de points), sans aucun texte, tirées
 * d'une graine propre au nom du fichier (deux images d'une même fiche restent distinctes). JPEG, dessinées en double
 * taille puis réduites (bords lissés).
 */
final class ReferenceImageGenerator
{
    /** @param string $top couleur #rrggbb du haut ; $bottom : du bas */
    public function generate(string $path, int $width, int $height, string $top, string $bottom): void
    {
        $scale = 2;
        [$w, $h] = [$width * $scale, $height * $scale];
        $topRgb = self::rgb($top);
        $bottomRgb = self::rgb($bottom);
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, true);
        for ($y = 0; $y < $h; $y++) {
            imageline($image, 0, $y, $w - 1, $y, imagecolorallocate($image, ...self::mix($topRgb, $bottomRgb, $y / ($h - 1))));
        }

        mt_srand(crc32(basename($path)));
        $min = min($w, $h);
        $white = [255, 255, 255];
        // Paysage large (bannière) : formes sur la moitié droite, le texte du site se place à gauche
        $x0 = $width / $height >= 1.8 ? 0.55 : 0.0;
        self::disc($image, self::pick($w, $x0 + 0.15, 1.0), self::pick($h, -0.1, 0.5), (int) ($min * self::rand(0.7, 1.0)), self::mix($topRgb, $white, 0.5), 70);
        self::disc($image, self::pick($w, $x0, 0.95), self::pick($h, 0.45, 1.05), (int) ($min * self::rand(0.35, 0.55)), self::mix($bottomRgb, $white, 0.3), 85);
        $radius = (int) ($min * self::rand(0.18, 0.3));
        imagesetthickness($image, max(1, (int) ($min * 0.018)));
        imagearc($image, self::pick($w, $x0 + 0.05, 0.9), self::pick($h, 0.1, 0.9), 2 * $radius, 2 * $radius, 0, 360, imagecolorallocatealpha($image, ...[...self::mix($topRgb, $white, 0.7), 80]));
        imagesetthickness($image, 1);
        $dot = imagecolorallocatealpha($image, 255, 255, 255, 95);
        [$gx, $gy, $step] = [self::pick($w, $x0 + 0.05, 0.75), self::pick($h, 0.1, 0.7), (int) ($min * 0.045)];
        for ($i = 0; $i < 25; $i++) {
            imagefilledellipse($image, $gx + ($i % 5) * $step, $gy + intdiv($i, 5) * $step, (int) ($step * 0.28), (int) ($step * 0.28), $dot);
        }

        $out = imagecreatetruecolor($width, $height);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, $w, $h);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        imagejpeg($out, $path, 86);
    }

    private static function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    }

    private static function mix(array $a, array $b, float $t): array
    {
        return array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $t), $a, $b);
    }

    private static function rand(float $a, float $b): float
    {
        return $a + ($b - $a) * mt_rand() / mt_getrandmax();
    }

    private static function pick(int $size, float $a, float $b): int
    {
        return (int) ($size * self::rand($a, $b));
    }

    private static function disc(\GdImage $image, int $x, int $y, int $r, array $rgb, int $alpha): void
    {
        imagefilledellipse($image, $x, $y, 2 * $r, 2 * $r, imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], $alpha));
    }
}
