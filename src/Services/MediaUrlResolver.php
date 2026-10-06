<?php

namespace App\Services;

class MediaUrlResolver
{
    private ?string $storagePublicUrl;

    public function __construct(?string $storagePublicUrl = null)
    {
        $this->storagePublicUrl = !empty($storagePublicUrl) ? rtrim($storagePublicUrl, '/') : null;
    }

    /**
     * Retourne le préfixe d'hôte public (CDN ou serveur local).
     */
    public function getPublicHost(?string $fallbackHost = null): string
    {
        if ($this->storagePublicUrl !== null) {
            if (str_starts_with($this->storagePublicUrl, '/') && !empty($fallbackHost)) {
                return rtrim($fallbackHost, '/') . $this->storagePublicUrl;
            }
            return $this->storagePublicUrl;
        }

        return $fallbackHost !== null ? rtrim($fallbackHost, '/') : '';
    }

    /**
     * Résout une URL complète à partir d'un sous-chemin relatif.
     * Si le chemin est déjà absolu (http:// ou https://), il est retourné tel quel.
     */
    public function resolveUrl(?string $subpath, ?string $fallbackHost = null): ?string
    {
        if (empty($subpath)) {
            return null;
        }

        if (str_starts_with($subpath, 'http://') || str_starts_with($subpath, 'https://')) {
            return $subpath;
        }

        $base = $this->getPublicHost($fallbackHost);
        $cleanSubpath = ltrim($subpath, '/');

        return !empty($base) ? $base . '/' . $cleanSubpath : '/' . $cleanSubpath;
    }

    /**
     * URL d'une image ou d'une vidéo enregistrée dans un champ de contenu (présentation, bannière, vidéo, service) :
     * un nom de fichier (téléversé par l'administration, dans $baseUrl), une URL absolue (https://…, telle quelle) ou
     * un média de la médiathèque par sa clé (« /media/secure/{clé} », écrit par l'éditeur des landing pages, servi par
     * l'hôte de $baseUrl).
     */
    public static function joinStored(?string $stored, string $baseUrl): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            return $stored;
        }
        if (str_starts_with($stored, '/media/secure/')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
            $host = parse_url($baseUrl, PHP_URL_HOST);
            $port = parse_url($baseUrl, PHP_URL_PORT);

            return $host ? sprintf('%s://%s%s%s', $scheme ?: 'https', $host, $port ? ':' . $port : '', $stored) : $stored;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($stored, '/');
    }

    // --- Base URLs par type d'asset ---

    public function getProductsBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/products';
    }

    public function getCategoriesBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/categories';
    }

    public function getSliderBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/slider';
    }

    public function getEmailLogosBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/email-logos';
    }

    public function getIconsBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/icons';
    }

    public function getCarrierBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/Carrier';
    }

    public function getTeamBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/team';
    }

    public function getCustomizationBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/customization';
    }

    public function getOptionsBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/options';
    }

    public function getExploreBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/explore';
    }

    public function getImagesBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/images';
    }

    public function getVideosBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/videos';
    }

    public function getMemoiresBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/uploads/memoires';
    }

    public function getSharedMediaBaseUrl(?string $fallbackHost = null): string
    {
        return $this->getPublicHost($fallbackHost) . '/assets/uploads/shared';
    }

    /**
     * Résout l'URL d'un SharedMedia selon son mode d'accès (public ou privé par clé).
     */
    public function resolveSharedMediaUrl(\App\Entity\SharedMedia $media, ?string $fallbackHost = null): string
    {
        if ($media->isPrivate() && !empty($media->getAccessKey())) {
            $host = $fallbackHost !== null ? rtrim($fallbackHost, '/') : '';
            return $host . '/media/secure/' . $media->getAccessKey();
        }

        $filename = $media->getFilename();
        if (empty($filename)) {
            return '';
        }

        if (str_starts_with($filename, 'http')) {
            return $filename;
        }

        return $this->getSharedMediaBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    // --- Helpers de résolution directe pour les DTOs et entités ---

    public function resolveProductImage(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getProductsBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    public function resolveCategoryImage(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getCategoriesBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    public function resolveSliderImage(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getSliderBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    public function resolveLogoImage(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getEmailLogosBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    public function resolveIconImage(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getIconsBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    public function resolveCarrierImage(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getCarrierBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }

    public function resolveMemoirePhoto(?string $filename, ?string $fallbackHost = null): ?string
    {
        if (empty($filename)) return null;
        if (str_starts_with($filename, 'http')) return $filename;
        return $this->getMemoiresBaseUrl($fallbackHost) . '/' . ltrim($filename, '/');
    }
}
