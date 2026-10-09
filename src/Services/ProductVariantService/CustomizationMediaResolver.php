<?php

namespace App\Services\ProductVariantService;

use App\Services\MediaUrlResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adresses des images de personnalisation (09/10/2026) : chaque fichier est cherché dans les dossiers où l'administration
 * les range réellement, et l'adresse pointe vers celui qui le contient ; fichier introuvable : null (le site affiche
 * alors l'image du produit). Combinaisons : customization/ (écran « Combinaisons »), puis products/ (ancien formulaire
 * de variante) ; icônes de valeur d'option : options/, puis icons/. Une adresse absolue enregistrée est rendue telle quelle.
 */
final class CustomizationMediaResolver
{
    public const COMBINATION_DIRS = ['customization', 'products'];
    public const OPTION_DIRS = ['options', 'icons'];
    private const STORAGE = '/var/storage/public_bucket/assets/uploads/';

    public function __construct(
        private readonly MediaUrlResolver $media,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    public function combinationUrl(?string $stored, string $rawHost): ?string
    {
        return $this->resolve($stored, self::COMBINATION_DIRS, $rawHost);
    }

    public function optionIconUrl(?string $stored, string $rawHost): ?string
    {
        return $this->resolve($stored, self::OPTION_DIRS, $rawHost);
    }

    /** Dossier qui contient le fichier d'une combinaison, ou null s'il est introuvable */
    public function combinationDir(?string $stored): ?string
    {
        return $this->dirOf($stored, self::COMBINATION_DIRS);
    }

    /** @param list<string> $dirs */
    private function resolve(?string $stored, array $dirs, string $rawHost): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }
        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://') || str_starts_with($stored, '/media/secure/')) {
            return MediaUrlResolver::joinStored($stored, $this->media->getPublicHost($rawHost));
        }
        $dir = $this->dirOf($stored, $dirs);

        return $dir === null ? null : $this->media->getPublicHost($rawHost) . '/assets/uploads/' . $dir . '/' . basename($stored);
    }

    /** @param list<string> $dirs */
    private function dirOf(?string $stored, array $dirs): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }
        foreach ($dirs as $dir) {
            if (is_file($this->projectDir . self::STORAGE . $dir . '/' . basename($stored))) {
                return $dir;
            }
        }

        return null;
    }
}
