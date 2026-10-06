<?php

namespace App\Services\LandingContentService;

use App\Dto\BaniereStatiqueOutputDto;
use App\Dto\BanniereOutputDto;
use App\Dto\PresentationGroupOutputDto;
use App\Dto\PresentationOutputDto;
use App\Dto\ServiceOfferOutputDto;
use App\Dto\VideoOutputDto;
use App\Entity\BaniereStatique;
use App\Entity\BaniereStatiqueTranslation;
use App\Entity\Banniere;
use App\Entity\BanniereTranslation;
use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Entity\PresentationGroupTranslation;
use App\Entity\PresentationTranslation;
use App\Entity\ServiceOffer;
use App\Entity\ServiceOfferTranslation;
use App\Entity\Video;
use App\Entity\VideoTranslation;

/**
 * Contenus des sections de landing page modifiables depuis l'éditeur (PATCH /api/{ressource}/{id}) : pour chaque
 * ressource, l'entité, sa traduction, les champs autorisés (liste blanche) et les étiquettes de cache à invalider.
 *
 * Nature d'un champ : text (texte, liste blanche HTML des compositions réglables), link (adresse d'un bouton),
 * image ou video (clé de la médiathèque ou URL https), color (couleur CSS). « translated » : écrit dans la langue
 * demandée (?locale=), sinon commun à toutes les langues.
 */
final class LandingContentSpec
{
    public const TEXT = 'text';
    public const LINK = 'link';
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const COLOR = 'color';

    /** Ressource => entité, traduction, base des images, champs, étiquettes de cache */
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
    ];

    /** Objet tel que le GET de la ressource le renvoie */
    public static function output(string $resource, object $entity, string $baseImageUrl, string $locale): object
    {
        return match ($resource) {
            'presentations' => new PresentationOutputDto($entity, $baseImageUrl, $locale),
            'presentation-groups' => new PresentationGroupOutputDto($entity, $baseImageUrl, $locale),
            'baniere-statiques' => new BaniereStatiqueOutputDto($entity, $baseImageUrl, $locale),
            'bannieres' => new BanniereOutputDto($entity, $baseImageUrl, $locale),
            'videos' => new VideoOutputDto($entity, $baseImageUrl, $locale),
            'service-offers' => new ServiceOfferOutputDto($entity, $baseImageUrl, $locale),
        };
    }
}
