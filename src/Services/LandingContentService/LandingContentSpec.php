<?php

namespace App\Services\LandingContentService;

use App\Dto\BaniereStatiqueOutputDto;
use App\Dto\BanniereOutputDto;
use App\Dto\CategoryOutputDTO;
use App\Dto\ExploreCardDto;
use App\Dto\HomeSliderDTO;
use App\Dto\PresentationGroupOutputDto;
use App\Dto\PresentationOutputDto;
use App\Dto\ProductDetailedOutputDTO;
use App\Dto\ServiceOfferOutputDto;
use App\Dto\VideoOutputDto;
use App\Entity\BaniereStatique;
use App\Entity\BaniereStatiqueTranslation;
use App\Entity\Banniere;
use App\Entity\BanniereTranslation;
use App\Entity\Categories;
use App\Entity\CategoriesTranslation;
use App\Entity\ExploreCard;
use App\Entity\ExploreCardTranslation;
use App\Entity\HomeSlider;
use App\Entity\HomeSliderTranslation;
use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Entity\PresentationGroupTranslation;
use App\Entity\PresentationTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\ServiceOffer;
use App\Entity\ServiceOfferTranslation;
use App\Entity\Video;
use App\Entity\VideoTranslation;
use App\Services\MediaUrlResolver;

/**
 * Contenus modifiables depuis les éditeurs (PATCH /api/{ressource}/{id}) : sections des landing pages et, depuis le
 * 08/10/2026, données de la boutique réglable (produit, catégorie, diapositive de l'accueil, carte « explorer »).
 * Pour chaque ressource : l'entité, sa traduction, le dossier des images, les champs autorisés (liste blanche) et
 * les étiquettes de cache à invalider.
 *
 * Nature d'un champ : text (texte, liste blanche HTML des compositions réglables), link (adresse d'un bouton),
 * image ou video (clé de la médiathèque ou URL https), images (liste de clés ou d'URL, remplace la galerie), color
 * (couleur CSS), number (entier en cents, positif ou nul), date (ISO 8601). « translated » : écrit dans la langue
 * demandée (?locale=), sinon commun à toutes les langues. Clés facultatives : localeField (nom du champ de langue de la
 * traduction, « language » par défaut), titleField (champ copié dans une traduction créée, « titre » par défaut),
 * aliases (nom de l'API => propriété de l'entité).
 */
final class LandingContentSpec
{
    public const TEXT = 'text';
    public const LINK = 'link';
    public const IMAGE = 'image';
    public const IMAGES = 'images';
    public const VIDEO = 'video';
    public const COLOR = 'color';
    public const NUMBER = 'number';
    public const DATE = 'date';

    /** Ressource => entité, traduction, base des images, champs [nature, traduit, maximum, obligatoire], étiquettes de cache */
    public const RESOURCES = [
        'presentations' => [
            'entity' => Presentation::class, 'translation' => PresentationTranslation::class, 'images' => 'slider',
            'fields' => [
                'titre' => [self::TEXT, true, 255, true], 'texte' => [self::TEXT, true, 20000, false],
                'texteBouton' => [self::TEXT, true, 255, false], 'lienBouton' => [self::LINK, true, 255, false],
                'image' => [self::IMAGE, false, 255, false],
            ],
            // une présentation est aussi lue à travers ses groupes
            'tags' => ['presentations_all', 'presentation_%d', 'presentation_groups_all'],
        ],
        'presentation-groups' => [
            'entity' => PresentationGroup::class, 'translation' => PresentationGroupTranslation::class, 'images' => 'slider',
            'fields' => ['titre' => [self::TEXT, true, 255, true], 'texte' => [self::TEXT, false, 20000, false]],
            'tags' => ['presentation_groups_all', 'presentation_group_%d'],
        ],
        'baniere-statiques' => [
            'entity' => BaniereStatique::class, 'translation' => BaniereStatiqueTranslation::class, 'images' => 'slider',
            'fields' => [
                'titre' => [self::TEXT, true, 255, true], 'texte' => [self::TEXT, true, 20000, false],
                'texteBouton' => [self::TEXT, true, 255, false], 'imageDeFond' => [self::IMAGE, false, 255, false],
                'colorBackground' => [self::COLOR, false, 255, false],
            ],
            'tags' => ['bannieres_statiques_all', 'baniere_statique_%d'],
        ],
        'bannieres' => [
            'entity' => Banniere::class, 'translation' => BanniereTranslation::class, 'images' => 'slider',
            'fields' => [
                'titre' => [self::TEXT, true, 255, true], 'texte' => [self::TEXT, true, 20000, false],
                'imageDeFond' => [self::IMAGE, false, 255, false],
            ],
            'tags' => ['bannieres_all', 'banniere_%d'],
        ],
        'videos' => [
            'entity' => Video::class, 'translation' => VideoTranslation::class, 'images' => 'slider',
            'fields' => [
                'titre' => [self::TEXT, true, 255, true], 'description' => [self::TEXT, true, 20000, false],
                'texteBouton' => [self::TEXT, true, 255, false], 'lienBouton' => [self::LINK, true, 255, false],
                'imageDeFond' => [self::IMAGE, false, 255, false], 'lienVideo' => [self::VIDEO, false, 255, false],
            ],
            'tags' => ['videos_all', 'video_%d'],
        ],
        'service-offers' => [
            'entity' => ServiceOffer::class, 'translation' => ServiceOfferTranslation::class, 'images' => 'email-logos',
            'fields' => [
                'titre' => [self::TEXT, true, 255, true], 'titreCommentaire' => [self::TEXT, true, 255, false],
                'descriptions' => [self::TEXT, true, 20000, false], 'logo' => [self::IMAGE, false, 255, false],
                'photoService' => [self::IMAGE, false, 255, false],
            ],
            'tags' => ['service_offers_all', 'service_offer_%d'],
        ],
        // Boutique réglable (08/10/2026) : fiches affichées par les blocs liés. Montants en cents.
        'products' => [
            'entity' => Product::class, 'translation' => ProductTranslation::class, 'images' => 'products',
            'localeField' => 'locale', 'titleField' => 'name',
            'fields' => [
                'name' => [self::TEXT, true, 255, true], 'description' => [self::TEXT, true, 20000, false],
                'moreinformations' => [self::TEXT, true, 50000, false],
                'price' => [self::NUMBER, false, 100000000, true], 'specialPrice' => [self::NUMBER, false, 100000000, false],
                'specialPriceFrom' => [self::DATE, false, 0, false], 'specialPriceTo' => [self::DATE, false, 0, false],
                'image' => [self::IMAGE, false, 255, false], 'pictures' => [self::IMAGES, false, 10, false],
            ],
            'tags' => ['products_all', 'products_by_category', 'products_by_offer'],
        ],
        'category' => [
            'entity' => Categories::class, 'translation' => CategoriesTranslation::class, 'images' => 'categories', 'titleField' => 'name',
            'fields' => ['name' => [self::TEXT, true, 255, true], 'description' => [self::TEXT, true, 20000, false], 'image' => [self::IMAGE, false, 255, false]],
            'tags' => ['categories_all', 'products_by_category'],
        ],
        'homeslider' => [
            'entity' => HomeSlider::class, 'translation' => HomeSliderTranslation::class, 'images' => 'slider', 'titleField' => 'title',
            'fields' => [
                'title' => [self::TEXT, true, 255, true], 'description' => [self::TEXT, true, 20000, false],
                'buttonMessage' => [self::TEXT, true, 255, false], 'buttonUrl' => [self::LINK, true, 255, false],
                'image' => [self::IMAGE, false, 255, false],
            ],
            'tags' => ['homeslider_all'],
        ],
        'explore-cards' => [
            'entity' => ExploreCard::class, 'translation' => ExploreCardTranslation::class, 'images' => 'explore', 'titleField' => 'standardTitle',
            'aliases' => ['imageUrl' => 'imagePath', 'videoUrl' => 'videoPath'],
            'fields' => [
                'standardTitle' => [self::TEXT, true, 255, true], 'differentTitle' => [self::TEXT, true, 255, false],
                'description' => [self::TEXT, true, 20000, false], 'link' => [self::LINK, false, 255, false],
                'imageUrl' => [self::IMAGE, false, 255, false], 'videoUrl' => [self::VIDEO, false, 255, false],
            ],
            'tags' => ['explore_cards_all'],
        ],
    ];

    /** Nom du champ de langue de la traduction */
    public static function localeField(string $resource): string
    {
        return self::RESOURCES[$resource]['localeField'] ?? 'language';
    }

    /** Champ copié dans une traduction créée à la volée */
    public static function titleField(string $resource): string
    {
        return self::RESOURCES[$resource]['titleField'] ?? 'titre';
    }

    /** Propriété de l'entité pour un nom de champ de l'API */
    public static function property(string $resource, string $field): string
    {
        return self::RESOURCES[$resource]['aliases'][$field] ?? $field;
    }

    /** Objet tel que le GET de la ressource le renvoie */
    public static function output(string $resource, object $entity, string $locale, MediaUrlResolver $urls, string $host, string $currency = 'CAD'): object
    {
        $public = $urls->getPublicHost($host);

        return match ($resource) {
            'presentations' => new PresentationOutputDto($entity, $urls->getSliderBaseUrl($host), $locale),
            'presentation-groups' => new PresentationGroupOutputDto($entity, $urls->getSliderBaseUrl($host), $locale),
            'baniere-statiques' => new BaniereStatiqueOutputDto($entity, $urls->getSliderBaseUrl($host), $locale),
            'bannieres' => new BanniereOutputDto($entity, $urls->getSliderBaseUrl($host), $locale),
            'videos' => new VideoOutputDto($entity, $urls->getSliderBaseUrl($host), $locale),
            'service-offers' => new ServiceOfferOutputDto($entity, $urls->getEmailLogosBaseUrl($host), $locale),
            'products' => new ProductDetailedOutputDTO($entity, $public, $locale, $currency),
            'category' => new CategoryOutputDTO($entity, $public, $locale),
            'homeslider' => HomeSliderDTO::fromEntity($entity, $public, $locale),
            'explore-cards' => ExploreCardDto::fromEntity($entity, $public, $locale),
        };
    }
}
