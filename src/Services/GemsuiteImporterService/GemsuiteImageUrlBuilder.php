<?php

namespace App\Services\GemsuiteImporterService;

class GemsuiteImageUrlBuilder
{
    private const IMAGE_BASE_URL = 'https://app.gem-books.com/?layout=image';

    /**
     * Construit une URL d'image complète et permanente pour GEM-SUITE.
     *
     * @param string|null $companyIdentifier L'identifiant de l'entreprise (ex: "michelalmonttest").
     * @param string|null $imagePath Le chemin relatif de l'image (ex: "/path/to/image.webp").
     * @return string L'URL complète de l'image, ou une chaîne vide si les informations sont manquantes.
     */
    public function buildUrl(?string $companyIdentifier, ?string $imagePath): string
    {
        if (empty($companyIdentifier) || empty($imagePath)) {
            return '';
        }

        return sprintf(
            '%s&d=%s&filename=%s',
            self::IMAGE_BASE_URL,
            $companyIdentifier,
            $imagePath
        );
    }
}
