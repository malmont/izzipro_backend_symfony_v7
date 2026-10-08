<?php

namespace App\Services\BoutiqueDemoService;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Images de la boutique de démonstration, dessinées localement (GD) : fond en dégradé de la couleur donnée, titre et
 * sous-titre. Écrites dans le stockage public partagé (var/storage/public_bucket/assets/uploads/<dossier>/), servi
 * sous /assets/uploads/ : aucune image d'un site client n'est reprise, aucune image externe n'est liée.
 */
final class DemoImageGenerator
{
    public const DIRS = ['products', 'categories', 'slider', 'explore'];
    private const TITLE_FONT = '/resources/fonts/memoires/PlayfairDisplay-Bold.ttf';
    private const TEXT_FONT = '/resources/fonts/memoires/Lato-Regular.ttf';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    /**
     * @param 'products'|'categories'|'slider'|'explore' $dir
     * @return string nom du fichier (à enregistrer dans l'entité)
     */
    public function generate(string $dir, string $name, string $title, string $subtitle, string $color, int $width = 1000, int $height = 1000): string
    {
        if (!in_array($dir, self::DIRS, true) || !preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException("Image de démonstration invalide : $dir/$name");
        }
        $target = $this->projectDir . '/var/storage/public_bucket/assets/uploads/' . $dir;
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException("Dossier $target impossible à créer");
        }
        $file = BoutiqueDemoCatalog::IMAGE_PREFIX . $name . '.jpg';

        $image = imagecreatetruecolor($width, $height);
        [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            // du ton donné (haut) vers un ton plus sombre (bas)
            $line = imagecolorallocate($image, (int) ($r * (1 - 0.45 * $t)), (int) ($g * (1 - 0.45 * $t)), (int) ($b * (1 - 0.45 * $t)));
            imageline($image, 0, $y, $width, $y, $line);
        }
        $white = imagecolorallocate($image, 255, 255, 255);
        $soft = imagecolorallocatealpha($image, 255, 255, 255, 60);
        imagefilledellipse($image, (int) ($width * 0.82), (int) ($height * 0.2), (int) ($width * 0.5), (int) ($width * 0.5), $soft);

        $titleSize = (int) round(min($width, $height) * 0.065);
        $lines = $this->wrap($title, self::TITLE_FONT, $titleSize, (int) ($width * 0.84));
        $y = (int) ($height * 0.62) - (count($lines) - 1) * (int) ($titleSize * 1.3);
        foreach ($lines as $line) {
            imagettftext($image, $titleSize, 0, (int) ($width * 0.08), $y, $white, $this->projectDir . self::TITLE_FONT, $line);
            $y += (int) ($titleSize * 1.3);
        }
        $textSize = (int) round($titleSize * 0.42);
        foreach ($this->wrap($subtitle, self::TEXT_FONT, $textSize, (int) ($width * 0.84)) as $line) {
            $y += (int) ($textSize * 0.6);
            imagettftext($image, $textSize, 0, (int) ($width * 0.08), $y, $white, $this->projectDir . self::TEXT_FONT, $line);
            $y += (int) ($textSize * 1.2);
        }
        imagettftext($image, (int) ($textSize * 0.8), 0, (int) ($width * 0.08), $height - (int) ($height * 0.06), $soft, $this->projectDir . self::TEXT_FONT, 'Démonstration');

        imagejpeg($image, $target . '/' . $file, 85);
        imagedestroy($image);

        return $file;
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $candidate = $current === '' ? $word : "$current $word";
            $box = imagettfbbox($size, 0, $this->projectDir . $font, $candidate);
            if ($current !== '' && ($box[2] - $box[0]) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        return $current === '' ? $lines : [...$lines, $current];
    }
}
