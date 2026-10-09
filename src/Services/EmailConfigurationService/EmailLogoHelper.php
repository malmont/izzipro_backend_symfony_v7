<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Services\MediaUrlResolver;

class EmailLogoHelper
{
    /** Dossier des logos d'e-mail dans le stockage public (écran EasyAdmin « Email Configuration ») */
    private const STORAGE_DIR = '/var/storage/public_bucket/assets/uploads/email-logos/';

    private ?MediaUrlResolver $mediaUrlResolver;
    private string $projectDir;

    public function __construct(?MediaUrlResolver $mediaUrlResolver = null, string $projectDir = '')
    {
        $this->mediaUrlResolver = $mediaUrlResolver;
        $this->projectDir = rtrim($projectDir, '/');
    }

    /**
     * Chemin du fichier du logo sur le serveur, pour l'incorporer à l'e-mail (image jointe « cid: ») : elle
     * s'affiche alors sans dépendre d'une adresse publique ni du blocage des images distantes. null si le logo est
     * une URL distante ou si le fichier est introuvable.
     */
    public function getLocalPath(?EmailConfiguration $emailConfig): ?string
    {
        $logo = $emailConfig?->getLogo();
        if (empty($logo) || $this->projectDir === '' || str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return null;
        }

        $path = $this->projectDir . self::STORAGE_DIR . basename($logo);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        return $this->emailSized($path) ?? $path;
    }

    /** Largeur du logo intégré aux courriels (affiché à 200 px au plus ; double densité pour les écrans Retina) */
    private const EMAIL_WIDTH = 400;

    /**
     * Copie allégée du logo pour les courriels (09/10/2026) : chaque courriel embarquait l'original (732 Ko pour demo,
     * soit près d'1 Mo par message). PNG redimensionné à 400 px de large, transparence gardée, régénéré quand
     * l'original change. Sans GD, original trop petit ou format non pris en charge : null (l'original sert).
     */
    private function emailSized(string $path): ?string
    {
        if (!function_exists('imagecreatefromstring') || filesize($path) < 40 * 1024) {
            return null;
        }
        $target = dirname($path) . '/email-' . self::EMAIL_WIDTH . '-' . pathinfo($path, PATHINFO_FILENAME) . '.png';
        if (is_file($target) && filemtime($target) >= filemtime($path)) {
            return $target;
        }
        try {
            $size = @getimagesize($path);
            $source = $size ? @imagecreatefromstring((string) file_get_contents($path)) : false;
            if (!$source || $size[0] <= self::EMAIL_WIDTH) {
                return null;
            }
            $height = (int) round($size[1] * self::EMAIL_WIDTH / $size[0]);
            $resized = imagecreatetruecolor(self::EMAIL_WIDTH, $height);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
            imagecopyresampled($resized, $source, 0, 0, 0, 0, self::EMAIL_WIDTH, $height, $size[0], $size[1]);
            $temporary = $target . '.' . bin2hex(random_bytes(4));
            if (!imagepng($resized, $temporary, 9) || !rename($temporary, $target)) {
                @unlink($temporary);

                return null;
            }

            return $target;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Génère l'URL complète (absolue) du logo utilisé dans les emails,
     * en tenant compte des URLs distantes ou uploads locaux.
     *
     * @param EmailConfiguration|null $emailConfig
     * @param string $baseUrl
     * @return string|null
     */
    public function getLogoUrl(?EmailConfiguration $emailConfig, string $baseUrl): ?string
    {
        if (!$emailConfig || empty($emailConfig->getLogo())) {
            return null;
        }

        $logoPath = $emailConfig->getLogo();

        // Si c'est déjà une URL absolue valide
        if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
            return $logoPath;
        }

        // Sinon on construit l'URL pour un fichier local ou CDN
        $baseUrl = self::origin($baseUrl);
        $host = $this->mediaUrlResolver ? $this->mediaUrlResolver->getPublicHost($baseUrl) : $baseUrl;
        $cleanedHost = rtrim($host, '/');
        return $cleanedHost . '/assets/uploads/email-logos/' . $logoPath;
    }

    /**
     * Origine (schéma + hôte) à partir de laquelle le logo est servi. Les appelants passent des valeurs diverses : un
     * hôte de frontend sans schéma (formulaire de contact), une adresse déjà complétée d'un chemin (commandes). Seule
     * une origine http(s) est gardée ; à défaut, le domaine du backend, qui sert le stockage public.
     */
    private static function origin(string $baseUrl): string
    {
        $parts = parse_url(trim($baseUrl));
        if (is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && !empty($parts['host'])) {
            return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return 'https://' . ($_ENV['BACKEND_BASE_DOMAIN'] ?? 'backend-strapi.online');
    }
}
