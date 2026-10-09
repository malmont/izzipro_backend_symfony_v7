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
use App\Entity\Color;
use App\Entity\Feature;
use App\Entity\FeatureTranslation;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOption;
use App\Entity\ProductOptionTranslation;
use App\Entity\ProductOptionValue;
use App\Entity\ProductOptionValueTranslation;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\SubscriptionPlan;
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
 *
 * Données de la boutique éditables depuis la page (09/10/2026) : variantes, options, valeurs et combinaisons de
 * personnalisation, textes des formules d'abonnement, atouts de l'accueil. Natures ajoutées : integer (entier positif
 * ou nul : stock), plain (une ligne de texte, sans balise), relation (identifiant d'une entité : relations[champ] =
 * [classe, propriété]), dollars (cents dans l'API, dollars en base : supplément d'option), localized (texte par langue
 * dans un champ JSON de l'entité), localized-lines (liste de lignes par langue ; maximum = nombre de lignes,
 * lineMax = longueur d'une ligne), boolean.
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
    public const INTEGER = 'integer';
    public const PLAIN = 'plain';
    public const RELATION = 'relation';
    public const DOLLARS = 'dollars';
    public const LOCALIZED = 'localized';
    public const LOCALIZED_LINES = 'localized-lines';
    public const BOOLEAN = 'boolean';

    /** Étiquettes de cache des listes de produits (une variante, un stock, une personnalisation y apparaissent) */
    private const PRODUCT_TAGS = ['products_all', 'products_by_category', 'products_by_offer', 'products_command', 'product_variants'];

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
        // Données de la boutique éditables depuis la page (09/10/2026)
        'product-variants' => [
            'entity' => ProductVariant::class, 'translation' => null, 'images' => 'products',
            'relations' => ['colorId' => [Color::class, 'color'], 'sizeId' => [Size::class, 'size']],
            'fields' => [
                'stockQuantity' => [self::INTEGER, false, 1000000, true], 'price' => [self::NUMBER, false, 100000000, false],
                'colorId' => [self::RELATION, false, 0, false], 'sizeId' => [self::RELATION, false, 0, false],
            ],
            'tags' => self::PRODUCT_TAGS,
        ],
        'customization-options' => [
            'entity' => ProductOption::class, 'translation' => ProductOptionTranslation::class, 'images' => 'options', 'titleField' => 'name',
            'fields' => ['name' => [self::PLAIN, true, 255, true]],
            'tags' => self::PRODUCT_TAGS,
        ],
        'customization-values' => [
            'entity' => ProductOptionValue::class, 'translation' => ProductOptionValueTranslation::class, 'images' => 'options', 'titleField' => 'value',
            'aliases' => ['name' => 'value', 'icon' => 'imagePreview'],
            'fields' => [
                'name' => [self::PLAIN, true, 255, true], 'priceDelta' => [self::DOLLARS, false, 100000000, false],
                'icon' => [self::IMAGE, false, 255, false],
            ],
            'tags' => self::PRODUCT_TAGS,
        ],
        'customization-combinations' => [
            'entity' => ProductCustomizationImage::class, 'translation' => null, 'images' => 'customization',
            'aliases' => ['image' => 'imagePath', 'stock' => 'numberOfPieces'],
            'fields' => ['image' => [self::IMAGE, false, 255, true], 'stock' => [self::INTEGER, false, 1000000, true]],
            'tags' => self::PRODUCT_TAGS,
        ],
        // Formules d'abonnement : textes seulement (prix, périodicité, essai : administration, liés à Stripe)
        'subscription-plans' => [
            'entity' => SubscriptionPlan::class, 'translation' => null, 'images' => 'products',
            'aliases' => ['name' => 'names', 'description' => 'descriptions', 'badge' => 'badges'],
            'fields' => [
                'name' => [self::LOCALIZED, false, 255, true], 'description' => [self::LOCALIZED, false, SubscriptionPlan::DESCRIPTION_LENGTH, false],
                'features' => [self::LOCALIZED_LINES, false, SubscriptionPlan::FEATURES_MAX, false, SubscriptionPlan::FEATURE_LENGTH],
                'badge' => [self::LOCALIZED, false, SubscriptionPlan::BADGE_LENGTH, false], 'highlighted' => [self::BOOLEAN, false, 0, true],
            ],
            'tags' => [],
        ],
        'features' => [
            'entity' => Feature::class, 'translation' => FeatureTranslation::class, 'images' => 'icons', 'titleField' => 'title',
            'aliases' => ['icon' => 'iconpath'],
            'fields' => ['title' => [self::PLAIN, true, 100, true], 'icon' => [self::IMAGE, false, 255, false]],
            'tags' => ['features_all'],
        ],
    ];

    /** Ressources de la boutique dont la sortie est construite par LandingContentOutput (services) */
    public const SERVICE_OUTPUT = ['product-variants', 'customization-options', 'customization-values', 'customization-combinations', 'subscription-plans', 'features'];

    /** Relation d'un champ « relation » : [classe de l'entité liée, propriété] */
    public static function relation(string $resource, string $field): array
    {
        return self::RESOURCES[$resource]['relations'][$field];
    }

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
