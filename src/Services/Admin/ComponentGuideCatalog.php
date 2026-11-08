<?php

namespace App\Services\Admin;

/**
 * Guide des composants du site (08/11/2026) : pour chaque écran EasyAdmin d'un composant de la landing page ou de la boutique, un nom
 * explicite, ce que le composant montre sur le site, où il apparaît, quoi remplir, et une illustration (gabarit SVG
 * `templates/admin/guide/svg/<svg>.html.twig`). Lu par `BaseTenantCrudController` (libellés de l'écran) et par le panneau de droite des
 * pages liste, création et modification (`templates/bundles/EasyAdminBundle/crud/*.html.twig`, fonction Twig `admin_component_guide`).
 */
class ComponentGuideCatalog
{
    /** @var array<string, array{key: string, title: string, singular: string, purpose: string, where: string, fields: list<string>, tip?: string, svg: string}> */
    public const GUIDES = [
        \App\Controller\Admin\BanniereCrudController::class => [
            'key' => 'banniere', 'title' => 'Diaporama d\'accueil (bannières)', 'singular' => 'Bannière du diaporama',
            'purpose' => 'Les grandes images qui défilent en haut de votre page d\'accueil, avec un titre et une phrase par image.',
            'where' => 'Section « Bannière (diaporama) » de la landing page. Les images défilent toutes seules, le visiteur peut passer à la suivante.',
            'fields' => ['Une image de fond large (paysage, au moins 1600 px)', 'Un titre court', 'Une phrase d\'accroche'],
            'tip' => 'Trois à cinq bannières suffisent ; une seule donne une image fixe.', 'svg' => 'banniere',
        ],
        \App\Controller\Admin\BaniereStatiqueCrudController::class => [
            'key' => 'banniere_statique', 'title' => 'Bannières fixes (image, titre, bouton)', 'singular' => 'Bannière fixe',
            'purpose' => 'Un bandeau qui ne bouge pas : une image ou une couleur de fond, un titre, un texte et un bouton.',
            'where' => 'Section « Bannière statique » de la landing page, souvent entre deux parties de la page pour appeler à l\'action.',
            'fields' => ['Un titre et un texte court', 'Une image de fond ou une couleur', 'Le texte du bouton'],
            'svg' => 'banniere_statique',
        ],
        \App\Controller\Admin\PresentationCrudController::class => [
            'key' => 'presentation', 'title' => 'Blocs de présentation (image, titre, texte, bouton)', 'singular' => 'Bloc de présentation',
            'purpose' => 'Une « carte » qui présente une chose : un service, une offre, un produit phare, un projet. Image, titre, texte et bouton.',
            'where' => 'Section « Présentation » de la landing page (un bloc seul), ou dans un « Groupe de blocs » (plusieurs côte à côte).',
            'fields' => ['Un titre', 'Un texte de deux à quatre phrases', 'Une image (facultative)', 'Un bouton : son texte et son lien'],
            'tip' => 'Pour afficher plusieurs blocs ensemble, créez-les ici puis rassemblez-les dans un Groupe de blocs.', 'svg' => 'presentation',
        ],
        \App\Controller\Admin\PresentationGroupCrudController::class => [
            'key' => 'presentation_group', 'title' => 'Groupes de blocs (plusieurs présentations côte à côte)', 'singular' => 'Groupe de blocs',
            'purpose' => 'Rassemble plusieurs blocs de présentation sous un même titre : « Nos services », « Nos formules », « Comment ça marche ».',
            'where' => 'Section « Groupe de présentations » de la landing page : le titre du groupe, puis les blocs en rangée ou en grille.',
            'fields' => ['Le titre du groupe et une phrase d\'introduction', 'Les blocs de présentation à inclure, dans l\'ordre voulu'],
            'svg' => 'presentation_group',
        ],
        \App\Controller\Admin\MarqueCrudController::class => [
            'key' => 'marque', 'title' => 'Logos de marques et partenaires', 'singular' => 'Marque (logos)',
            'purpose' => 'Une rangée de logos : les marques que vous vendez, vos partenaires, vos certifications.',
            'where' => 'Section « Marque » de la landing page : les logos défilent ou s\'alignent sur une ligne.',
            'fields' => ['Un titre pour la rangée (« Ils nous font confiance »)', 'Les logos, un fichier par marque, fond transparent de préférence', 'Une catégorie si vous voulez les grouper'],
            'svg' => 'marque',
        ],
        \App\Controller\Admin\CategorieMarqueCrudController::class => [
            'key' => 'categorie_marque', 'title' => 'Groupes de logos (catégories de marques)', 'singular' => 'Groupe de logos',
            'purpose' => 'Classe les logos en groupes nommés : « Partenaires », « Marques distribuées », « Labels ».',
            'where' => 'Dans la section « Marque », chaque groupe fait une rangée avec son nom.',
            'fields' => ['Le nom du groupe', 'Les marques qui en font partie'],
            'svg' => 'categorie_marque',
        ],
        \App\Controller\Admin\MultilienCrudController::class => [
            'key' => 'multilien', 'title' => 'Boutons de liens (page de liens)', 'singular' => 'Bouton de lien',
            'purpose' => 'Une liste de gros boutons, un par lien : réseaux sociaux, prise de rendez-vous, menu, catalogue. Comme une page « lien en bio ».',
            'where' => 'Section « Liens Multiples » de la landing page.',
            'fields' => ['Le texte du bouton', 'L\'adresse du lien', 'Une image de fond (facultative)'],
            'svg' => 'multilien',
        ],
        \App\Controller\Admin\RechercheCrudController::class => [
            'key' => 'recherche', 'title' => 'Barre de recherche (section)', 'singular' => 'Section de recherche',
            'purpose' => 'Un bandeau avec un titre, un texte et une barre de recherche qui mène au catalogue.',
            'where' => 'Section « Recherche » de la landing page, en général près du haut de la page.',
            'fields' => ['Le titre et le texte du bandeau', 'Une image de fond'],
            'svg' => 'recherche',
        ],
        \App\Controller\Admin\ServiceOfferCrudController::class => [
            'key' => 'service_offer', 'title' => 'Prestations réservables (rendez-vous et devis)', 'singular' => 'Prestation',
            'purpose' => 'Vos prestations avec leur photo, leur description et leur durée. Le visiteur choisit une prestation puis demande un rendez-vous ou un devis.',
            'where' => 'Sections « Services » et « Réservation & Devis » de la landing page.',
            'fields' => ['Le nom de la prestation et sa famille', 'Une photo et une description', 'La durée et la case « réservable »', 'L\'ordre d\'affichage'],
            'svg' => 'service_offer',
        ],
        \App\Controller\Admin\ServiceFamilyCrudController::class => [
            'key' => 'service_family', 'title' => 'Familles de prestations', 'singular' => 'Famille de prestations',
            'purpose' => 'Les rubriques qui regroupent vos prestations : « Coiffure », « Soins », « Entretien ».',
            'where' => 'Dans les sections « Services » et « Réservation & Devis », chaque famille fait un onglet ou un groupe.',
            'fields' => ['Le nom de la famille', 'Son ordre'],
            'svg' => 'service_family',
        ],
        \App\Controller\Admin\VideoCrudController::class => [
            'key' => 'video', 'title' => 'Vidéos (section vidéo)', 'singular' => 'Vidéo',
            'purpose' => 'Une vidéo mise en avant avec un titre, un texte et un bouton. Lien YouTube, Vimeo ou fichier de la médiathèque.',
            'where' => 'Section « Vidéo » de la landing page.',
            'fields' => ['Le lien de la vidéo', 'Une image de couverture (affichée avant la lecture)', 'Titre, texte et bouton'],
            'svg' => 'video',
        ],
        \App\Controller\Admin\EmbedCrudController::class => [
            'key' => 'embed', 'title' => 'Contenus intégrés (carte, agenda, vidéo externe)', 'singular' => 'Contenu intégré',
            'purpose' => 'Un cadre qui affiche une page d\'un autre service : carte Google, agenda de réservation, formulaire externe, lecteur.',
            'where' => 'Section « Contenu Intégré » de la landing page.',
            'fields' => ['Un titre', 'L\'adresse à intégrer (donnée par le service externe, souvent « embed » ou « iframe »)'],
            'svg' => 'embed',
        ],
        \App\Controller\Admin\EmploiCrudController::class => [
            'key' => 'emploi', 'title' => 'Offres d\'emploi (page Carrières)', 'singular' => 'Offre d\'emploi',
            'purpose' => 'Vos postes ouverts, chacun avec sa description et un bouton « Postuler » qui ouvre le formulaire de candidature.',
            'where' => 'Section « Candidature » de la landing page.',
            'fields' => ['Le titre du poste', 'La description (missions, profil, conditions)'],
            'svg' => 'emploi',
        ],
        \App\Controller\Admin\CandidatureCrudController::class => [
            'key' => 'candidature', 'title' => 'Candidatures reçues', 'singular' => 'Candidature',
            'purpose' => 'Les candidatures envoyées depuis le site : coordonnées, expérience, disponibilité, lien vers le CV. Rien à créer ici, vous consultez.',
            'where' => 'Reçues par le formulaire de la section « Candidature ».',
            'fields' => ['Lecture seule : ouvrez une candidature pour voir le détail et le CV'],
            'svg' => 'candidature',
        ],
        \App\Controller\Admin\LandingPageSettingCrudController::class => [
            'key' => 'landing_config', 'title' => 'Pages du site (composition enregistrée)', 'singular' => 'Composition des pages',
            'purpose' => 'La composition de vos pages : les onglets du menu, les sections de chaque page et leur mise en page. C\'est le fichier que l\'éditeur du site enregistre.',
            'where' => 'Tout le site vitrine. Pour composer ou retoucher, passez par l\'éditeur sur le site (bouton « Éditeur » en bas à droite, administrateur connecté) ou par « Mon site pas à pas ».',
            'fields' => ['Réservé aux utilisateurs techniques : la composition en JSON', 'Un contenu invalide est refusé à l\'enregistrement'],
            'tip' => 'Les contenus (textes, images) des sections se règlent dans les écrans de cette rubrique ; la mise en page, dans l\'éditeur.', 'svg' => 'landing_config',
        ],
        \App\Controller\Admin\HomeSliderCrudController::class => [
            'key' => 'home_slider', 'title' => 'Diaporama de l\'accueil de la boutique', 'singular' => 'Image du diaporama',
            'purpose' => 'Les images qui défilent en haut de l\'accueil de la boutique, avec un titre, un texte et un bouton vers une page ou un produit.',
            'where' => 'Accueil de la boutique, famille « Diaporama de l\'accueil ».',
            'fields' => ['Une image large', 'Titre et texte', 'Le texte et le lien du bouton', 'La case « affichée »'],
            'svg' => 'home_slider',
        ],
        \App\Controller\Admin\FeatureCrudController::class => [
            'key' => 'feature', 'title' => 'Atouts de la boutique (icône + phrase)', 'singular' => 'Atout',
            'purpose' => 'Une rangée de petits arguments avec une icône : « Livraison 48 h », « Paiement sécurisé », « Fabriqué ici ».',
            'where' => 'Accueil de la boutique, famille « Atouts ».',
            'fields' => ['Un titre court', 'Une icône (fichier)', 'L\'ordre'],
            'svg' => 'feature',
        ],
        \App\Controller\Admin\ExploreCardCrudController::class => [
            'key' => 'explore_card', 'title' => 'Cartes « Explorer » (accueil de la boutique)', 'singular' => 'Carte Explorer',
            'purpose' => 'Des cartes avec image ou vidéo, titre et lien, pour envoyer le visiteur vers une catégorie, une collection ou une page.',
            'where' => 'Accueil de la boutique, famille « Cartes explorer » ; aussi utilisable sur la landing page.',
            'fields' => ['Un titre', 'Une image ou une vidéo', 'Le lien de destination', 'Une description courte'],
            'svg' => 'explore_card',
        ],
        \App\Controller\Admin\BoutiqueSettingCrudController::class => [
            'key' => 'boutique_config', 'title' => 'Pages de la boutique (composition enregistrée)', 'singular' => 'Composition de la boutique',
            'purpose' => 'La composition des pages de la boutique : accueil, catalogue, fiche produit, panier, paiement, compte. Enregistrée par l\'éditeur du site.',
            'where' => 'Toute la boutique en ligne. Pour composer ou retoucher, passez par l\'éditeur sur le site ou par « Mon site pas à pas ».',
            'fields' => ['Réservé aux utilisateurs techniques : la composition en JSON', 'Un contenu invalide est refusé'],
            'svg' => 'boutique_config',
        ],
    ];

    /** @return array{key: string, title: string, singular: string, purpose: string, where: string, fields: list<string>, tip?: string, svg: string}|null */
    public static function for(?string $controllerFqcn): ?array
    {
        return $controllerFqcn !== null ? (self::GUIDES[ltrim($controllerFqcn, '\\')] ?? null) : null;
    }
}
