<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Services\MediaUrlResolver;

class EmailLogoHelper
{
    private ?MediaUrlResolver $mediaUrlResolver;

    public function __construct(?MediaUrlResolver $mediaUrlResolver = null)
    {
        $this->mediaUrlResolver = $mediaUrlResolver;
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
        $host = $this->mediaUrlResolver ? $this->mediaUrlResolver->getPublicHost($baseUrl) : $baseUrl;
        $cleanedHost = rtrim($host, '/');
        return $cleanedHost . '/assets/uploads/email-logos/' . $logoPath;
    }
}
