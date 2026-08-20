<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;

class EmailLogoHelper
{
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

        // Sinon on construit l'URL pour un fichier local mis bout à bout avec l'adresse du serveur
        $cleanedHost = rtrim($baseUrl, '/');
        return $cleanedHost . '/assets/uploads/email-logos/' . $logoPath;
    }
}
