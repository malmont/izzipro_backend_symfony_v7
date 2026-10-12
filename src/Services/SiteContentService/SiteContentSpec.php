<?php

namespace App\Services\SiteContentService;

/**
 * Contenu d'exemple d'un site (12/10/2026) : fiches copiées du site de référence (base gmasuite, modèle de tout nouveau
 * site) et marquées is_demo jusqu'à ce que le client les modifie. Pour chaque ressource (noms de l'éditeur,
 * LandingContentSpec) : table, portée (landing, boutique), colonne du libellé, traduction [table, colonne vers la
 * fiche, colonne de langue, colonne du libellé], champs de contenu à remplacer (noms de l'API de l'éditeur).
 *
 * L'ordre est l'ordre de copie (les présentations avant leurs groupes, les catégories avant les produits). Les options
 * de personnalisation sont copiées avec les produits qui les utilisent.
 */
final class SiteContentSpec
{
    public const SCOPES = ['landing', 'boutique'];
    public const MODES = ['empty', 'add'];

    public const RESOURCES = [
        'presentations' => [
            'table' => 'presentation', 'scopes' => ['landing'], 'label' => 'titre',
            'translation' => ['presentation_translation', 'presentation_id', 'language', 'titre'],
            'fields' => ['titre', 'texte', 'texteBouton', 'lienBouton', 'image'],
        ],
        'presentation-groups' => [
            'table' => 'presentation_group', 'scopes' => ['landing'], 'label' => 'titre',
            'translation' => ['presentation_group_translation', 'presentation_group_id', 'language', 'titre'],
            'fields' => ['titre', 'texte'],
        ],
        'baniere-statiques' => [
            'table' => 'baniere_statique', 'scopes' => ['landing'], 'label' => 'titre',
            'translation' => ['baniere_statique_translation', 'baniere_statique_id', 'language', 'titre'],
            'fields' => ['titre', 'texte', 'texteBouton', 'imageDeFond'],
        ],
        'bannieres' => [
            'table' => 'banniere', 'scopes' => ['landing'], 'label' => 'titre',
            'translation' => ['banniere_translation', 'banniere_id', 'language', 'titre'],
            'fields' => ['titre', 'texte', 'imageDeFond'],
        ],
        'videos' => [
            'table' => 'video', 'scopes' => ['landing'], 'label' => 'titre',
            'translation' => ['video_translation', 'video_id', 'language', 'titre'],
            'fields' => ['titre', 'description', 'texteBouton', 'lienBouton', 'imageDeFond', 'lienVideo'],
        ],
        'service-offers' => [
            'table' => 'service_offer', 'scopes' => ['landing'], 'label' => 'titre',
            'translation' => ['service_offer_translation', 'service_offer_id', 'language', 'titre'],
            'fields' => ['titre', 'titreCommentaire', 'descriptions', 'photoService'],
        ],
        'marques' => [
            'table' => 'marque', 'scopes' => ['landing'], 'label' => 'titre', 'translation' => null,
            'fields' => ['titre', 'logo'],
        ],
        'explore-cards' => [
            'table' => 'explore_card', 'scopes' => ['landing', 'boutique'], 'label' => 'standard_title',
            'translation' => ['explore_card_translation', 'explore_card_id', 'language', 'standard_title'],
            'fields' => ['standardTitle', 'description', 'imageUrl'],
        ],
        'category' => [
            'table' => 'categories', 'scopes' => ['boutique'], 'label' => 'name',
            'translation' => ['categories_translation', 'category_id', 'language', 'name'],
            'fields' => ['name', 'description', 'image'],
        ],
        'customization-options' => [
            'table' => 'product_option', 'scopes' => ['boutique'], 'label' => 'name',
            'translation' => ['product_option_translation', 'product_option_id', 'language', 'name'],
            'fields' => ['name'],
        ],
        'products' => [
            'table' => 'product', 'scopes' => ['boutique'], 'label' => 'name',
            'translation' => ['product_translation', 'product_id', 'locale', 'name'],
            'fields' => ['name', 'description', 'price', 'image', 'pictures'],
        ],
        'homeslider' => [
            'table' => 'home_slider', 'scopes' => ['boutique'], 'label' => 'title',
            'translation' => ['home_slider_translation', 'home_slider_id', 'language', 'title'],
            'fields' => ['title', 'description', 'buttonMessage', 'image'],
        ],
        'features' => [
            'table' => 'feature', 'scopes' => ['boutique'], 'label' => 'title',
            'translation' => ['feature_translation', 'feature_id', 'language', 'title'],
            'fields' => ['title', 'icon'],
        ],
    ];

    /** Ressources copiées pour ces portées, dans l'ordre de copie */
    public static function resourcesFor(array $scopes): array
    {
        return array_keys(array_filter(self::RESOURCES, fn (array $spec) => array_intersect($spec['scopes'], $scopes) !== []));
    }
}
