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

        return is_file($path) && is_readable($path) ? $path : null;
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
