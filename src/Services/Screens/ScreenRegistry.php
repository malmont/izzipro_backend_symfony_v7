<?php

namespace App\Services\Screens;

/**
 * LE REGISTRE DES ÉCRANS (lot AG-3a, 16/11/2026, `docs/ecrans-et-contextes.md`). Un seul endroit range chaque écran de l'application
 * sous un code, avec sa FAMILLE, son MODULE, et dit à quel écran appartient chaque route de l'API et chaque section d'EasyAdmin.
 *
 * Ce lot ne change AUCUN DROIT : le registre dit ce qu'un menu peut montrer (`GET /api/me/contexts`). Le refus par mode est le lot
 * AG-3b, qui lira ce même registre : une route que le registre ne range pas y sera FERMÉE, jamais ouverte.
 *
 * Règle de tenue : toute route nouvelle se range ici, dans un écran ou dans une liste « hors écran » nommée ; un essai relit toutes
 * les routes de l'application et tous les écrans d'EasyAdmin et échoue sur la première qui n'est pas rangée
 * (`tests/Functional/Screens/ScreenRegistryTest.php`).
 */
final class ScreenRegistry
{
    public const CONSTRUCTION = 'construction';
    public const VENTE = 'vente';
    public const CLIENTS = 'clients';
    public const GESTION = 'gestion';
    public const PERTES = 'pertes';
    public const ASSISTANCE = 'assistance';
    public const CONTENUS = 'contenus';
    public const PLATEFORME = 'plateforme';
    public const AGENCE = 'agence';
    /** La route des contextes : toute personne connectée la lit, même un compte borné à un seul espace (agence, assistance) */
    public const CONTEXTS_PATH = '/api/me/contexts';

    /** Les familles : ce qu'un mode ouvre ou ferme d'un bloc */
    public const FAMILIES = [
        self::CONSTRUCTION => 'Construction et entretien du site : catalogue, pages, apparence, réglages, ouverture',
        self::VENTE => 'Ventes : commandes, paiements, caisse, expéditions',
        self::CLIENTS => 'Clients : comptes, demandes reçues, avis, réservations, infolettre',
        self::GESTION => 'Gestion : achats, périodes, trésorerie, statistiques, console IA, bilans',
        self::PERTES => 'Prévention des pertes',
        self::ASSISTANCE => 'Assistance : demandes des clients',
        self::CONTENUS => 'Contenus personnels : Mémoires Vivantes et Boussole',
        self::PLATEFORME => 'Plateforme : écrans du super administrateur',
        self::AGENCE => 'Espace de l\'agence : ses sites clients, lus de son site d\'attache',
    ];

    private const ADMIN = ['ROLE_ADMIN'];
    private const G = ['family' => self::GESTION, 'module' => 'app.management'];
    private const P = ['family' => self::PERTES, 'module' => 'module.loss_prevention'];
    private const C = ['family' => self::CONSTRUCTION, 'module' => null];
    private const SHOP = ['family' => self::CONSTRUCTION, 'module' => 'module.boutique'];
    private const PAGE = ['family' => self::CONSTRUCTION, 'module' => 'module.landing'];

    /**
     * Les écrans. code => famille, module (clé de `FeatureCatalog::GOVERNED`, ou null : l'écran ne dépend d'aucun module), et au besoin
     * `roles` (un de ces rôles suffit ; à défaut `ROLE_ADMIN`) et `surface` : `web` (l'espace d'administration du web et l'application
     * de gestion) ou `easyadmin`. Les codes de la gestion et des pertes sont ceux que les applications utilisent déjà.
     *
     * @var array<string, array{family: string, module: ?string, roles?: list<string>, surface?: string}>
     */
    public const SCREENS = [
        // --- Construction (web)
        'catalogue' => self::SHOP, 'catalogue.ia' => self::SHOP, 'medias' => self::C,
        'codes-barres' => ['family' => self::CONSTRUCTION, 'module' => 'app.management'],
        'pages' => self::PAGE, 'pages.contenus' => self::PAGE, 'pages.ia' => self::PAGE, 'apparence' => self::C,
        'site.reglages' => self::C, 'site.domaine' => self::C, 'site.modules' => self::C, 'site.ouverture' => self::C, 'site.aide' => self::C,
        'imports' => self::SHOP,
        // --- Hors construction, bien que rangés près d'elle : leur réponse ENTIÈRE porte des ventes ou des clients
        // l'assistant du site lit les commandes et les clients, et propose remboursement, annulation, capture
        'site.assistant' => ['family' => self::VENTE, 'module' => null],
        // le journal des modifications garde l'avant / après de tout le site : coûts, commandes, adresses, comptes
        'site.journal' => ['family' => self::VENTE, 'module' => null],
        'imports.commandes' => ['family' => self::VENTE, 'module' => 'module.boutique'],
        'imports.clients' => ['family' => self::CLIENTS, 'module' => 'module.boutique'],
        // --- Clients, assistance, contenus (web)
        'clients.demandes' => ['family' => self::CLIENTS, 'module' => null],
        'assistance' => ['family' => self::ASSISTANCE, 'module' => 'module.support', 'roles' => ['ROLE_ADMIN', 'ROLE_SUPPORT']],
        'assistance.demande' => ['family' => self::ASSISTANCE, 'module' => 'module.support', 'roles' => ['ROLE_ADMIN', 'ROLE_SUPPORT']],
        'assistance-reglages' => ['family' => self::ASSISTANCE, 'module' => 'module.support'],
        'memoires.console' => ['family' => self::CONTENUS, 'module' => 'module.memoires', 'roles' => ['ROLE_ADMIN', 'ROLE_MEMOIRES_RESPONSABLE']],
        'boussole.conseiller' => ['family' => self::CONTENUS, 'module' => 'module.boussole', 'roles' => ['ROLE_ADMIN', 'ROLE_BOUSSOLE_CONSEILLER']],
        // --- Ventes (web) : un seul code, émis par « Ma journée »
        'commandes.a_expedier' => ['family' => self::VENTE, 'module' => 'module.boutique'],
        // --- Gestion (codes de `ConsoleScreens::SCREENS`, des guides d'écran, des séquences et de « Ma journée »)
        'gestion.home' => self::G, 'sequences' => self::G, 'sequences.detail' => self::G, 'conseils' => self::G, 'conversations' => self::G, 'conversation' => self::G,
        'theme' => self::G, 'commandes' => self::G, 'a-recevoir' => self::G, 'a-classer' => self::G, 'fournisseurs' => self::G, 'frais-de-port' => self::G,
        'transporteurs' => self::G, 'voyages' => self::G, 'voyages.suivi' => self::G, 'articles' => self::G, 'documents.scan' => self::G, 'prix' => self::G,
        'promotions' => self::G, 'locations.prix' => self::G, 'locations' => self::G, 'publicite' => self::G, 'publicite.campagne' => self::G, 'campagnes.new' => self::G,
        'tresorerie' => self::G, 'frais-fixes' => self::G, 'notes-de-frais' => self::G, 'periodes' => self::G, 'periode' => self::G, 'periode.budget' => self::G,
        'periode.simulation' => self::G, 'preparation' => self::G, 'statistiques' => self::G, 'statistiques.annee' => self::G, 'rapports' => self::G, 'reglages' => self::G,
        'reprise' => self::G, 'lexique' => self::G, 'notifications' => self::G, 'exercice' => self::G, 'annee' => self::G, 'stock.low' => self::G,
        // anciens codes de la gestion, gardés : les applications en place les envoient encore
        'accueil' => self::G, 'journee' => self::G, 'periode.resultat' => self::G, 'periode.preparation' => self::G, 'commande' => self::G, 'article' => self::G,
        'stock' => self::G, 'tresorerie.rapprochements' => self::G, 'documents' => self::G, 'simulateur' => self::G, 'console' => self::G,
        // --- Prévention des pertes
        'pertes.home' => self::P, 'pertes.constats' => self::P, 'pertes.constat' => self::P, 'pertes.regles' => self::P, 'pertes.reglages' => self::P,
        'pertes.exercices' => self::P, 'pertes.pieces' => self::P, 'pertes.installation' => self::P, 'pertes.boitiers' => self::P,
        // --- Plateforme (web)
        'plateforme.pilotage' => ['family' => self::PLATEFORME, 'module' => null, 'roles' => ['ROLE_SUPER_ADMIN']],
        'plateforme.agences' => ['family' => self::PLATEFORME, 'module' => null, 'roles' => ['ROLE_SUPER_ADMIN']],
        'plateforme.sites' => ['family' => self::PLATEFORME, 'module' => null, 'roles' => ['ROLE_SUPER_ADMIN']],
        // --- Espace de l'agence
        'agence.clients' => ['family' => self::AGENCE, 'module' => null, 'roles' => ['ROLE_AGENCY', 'ROLE_AGENCY_MEMBER']],
        // --- EasyAdmin : une entrée par section du menu, coupée là où la famille change
        'admin.accueil' => self::C + ['surface' => 'easyadmin'],
        'admin.entreprise' => self::C + ['surface' => 'easyadmin'],
        'admin.vitrine' => self::PAGE + ['surface' => 'easyadmin'],
        'admin.boutique-pages' => self::SHOP + ['surface' => 'easyadmin'],
        'admin.pages-avancees' => self::C + ['surface' => 'easyadmin'],
        'admin.ia' => self::C + ['surface' => 'easyadmin'],
        'admin.medias' => self::C + ['surface' => 'easyadmin'],
        'admin.catalogue' => self::SHOP + ['surface' => 'easyadmin'],
        'admin.livraison' => self::SHOP + ['surface' => 'easyadmin'],
        'admin.taxes' => self::C + ['surface' => 'easyadmin'],
        'admin.location' => self::SHOP + ['surface' => 'easyadmin'],
        'admin.commandes-reglages' => self::SHOP + ['surface' => 'easyadmin'],
        'admin.commandes' => ['family' => self::VENTE, 'module' => 'module.boutique', 'surface' => 'easyadmin'],
        'admin.paiement' => ['family' => self::VENTE, 'module' => null, 'surface' => 'easyadmin'],
        'admin.expeditions' => ['family' => self::VENTE, 'module' => 'module.boutique', 'surface' => 'easyadmin'],
        'admin.abonnements' => ['family' => self::VENTE, 'module' => 'module.boutique', 'surface' => 'easyadmin'],
        'admin.stock' => ['family' => self::VENTE, 'module' => 'module.boutique', 'surface' => 'easyadmin'],
        'admin.caisse' => ['family' => self::VENTE, 'module' => 'module.pos', 'surface' => 'easyadmin'],
        'admin.controles' => ['family' => self::VENTE, 'module' => null, 'surface' => 'easyadmin'],
        'admin.assistant' => ['family' => self::VENTE, 'module' => null, 'surface' => 'easyadmin'],
        'admin.clients' => ['family' => self::CLIENTS, 'module' => null, 'surface' => 'easyadmin'],
        'admin.utilisateurs' => ['family' => self::CLIENTS, 'module' => null, 'surface' => 'easyadmin'],
        'admin.achats' => self::G + ['surface' => 'easyadmin'],
        'admin.memoires' => ['family' => self::CONTENUS, 'module' => 'module.memoires', 'surface' => 'easyadmin'],
        'admin.boussole' => ['family' => self::CONTENUS, 'module' => 'module.boussole', 'surface' => 'easyadmin'],
        'admin.maintenance' => ['family' => self::PLATEFORME, 'module' => null, 'roles' => ['ROLE_SUPER_ADMIN'], 'surface' => 'easyadmin'],
    ];

    /**
     * Les listes « hors écran » : des routes qui ne sont l'écran d'administration de personne. Chacune est nommée : AG-3b décidera
     * liste par liste ce qu'un mode en garde.
     */
    public const OFF_SCREEN = [
        '@public' => 'Site public : lectures et envois d\'un visiteur ou d\'un client (catalogue, pages, panier, paiement, formulaires)',
        '@compte' => 'Connexion et compte de la personne connectée (profil, adresses, ses commandes, ses abonnements, ses avis, ses contextes)',
        '@apps' => 'Démarrage des applications : recherche du site, activation, profil et thème',
        '@caisse' => 'Application de caisse',
        '@memoires' => 'Mémoires Vivantes et Living Memory : livres, chapitres, invitations',
        '@boussole' => 'Boussole ESG : espace des entreprises',
        '@boitier' => 'Boîtier de la prévention des pertes (matériel)',
        '@technique' => 'Technique : état du serveur, webhooks, fichiers, synchronisation du front, création de site par clé',
        '@ancien' => 'Anciennes pages de la boutique servies par le backend',
    ];

    private const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Le rangement des routes : la PREMIÈRE règle dont l'expression reconnaît le chemin décide. La cible est un code d'écran ou une
     * liste hors écran ; quand lecture et écriture n'ont pas le même propriétaire, un tableau méthode => cible (`*` : les autres).
     * Les expressions valent pour un chemin réel comme pour le gabarit d'une route (`{id}` est un segment comme un autre).
     *
     * @var list<array{0: string, 1: string|array<string, string>}>
     */
    public const ROUTES = [
        // --- hors écran : connexion, compte, applications, technique
        ['#^/api/me/contexts$#', '@compte'],
        ['#^/api/(login|logout|token|otp-verify|register|resend-verification|validate-token|password-reset|import-invitation)(/|$)#', '@compte'],
        ['#^/api/(profile|account|adresses|ordersuser|notifications|subscriptions|reviews|payment)(/|$)#', '@compte'],
        ['#^/api/(tenant|app|app-profile|apps|mobile)(/|$)#', '@apps'],
        ['#^/api/(health|webhooks|landingpage-config)(/|$)#', '@technique'],
        // (API Platform : sa documentation, ses erreurs, ses contextes JSON-LD, son point d'entrée)
        ['#^/api/(\.well-known|errors|validation_errors|docs|contexts)([./]|$)#', '@technique'],
        ['#^/api/(\{index\}|index)(\.[^/]+)?$#', '@technique'],
        ['#^/(api/)?stripe/webhook$#', '@technique'],
        ['#^/(assets|uploads|media|bucket-simulator|setup)(/|$)#', '@technique'],
        ['#^/api/agency(/|$)#', 'agence.clients'],
        ['#^/api/pertes/boitier(/|$)#', '@boitier'],
        ['#^/api/(pos|caisse|cash-registers)(/|$)#', '@caisse'],
        ['#^/api/order/create-multi-payment$#', '@caisse'],
        // --- Mémoires Vivantes, Boussole
        ['#^/api/memoires/admin(/|$)#', 'memoires.console'],
        ['#^/api/memoires(/|$)#', '@memoires'],
        ['#^/api/boussole/team(/|$)#', 'boussole.conseiller'],
        ['#^/api/boussole(/|$)#', '@boussole'],
        // --- plateforme
        ['#^/api/platform/agencies(/|$)#', 'plateforme.agences'],
        ['#^/api/platform/(sites|site-requests)(/|$)#', 'plateforme.sites'],
        ['#^/api/platform(/|$)#', 'plateforme.pilotage'],
        // --- assistance
        ['#^/api/support/(settings|agents)(/|$)#', 'assistance-reglages'],
        ['#^/api/support/tickets/[^/]+#', 'assistance.demande'],
        ['#^/api/support(/|$)#', 'assistance'],
        // --- prévention des pertes
        ['#^/api/gestion/pertes/constats/[^/]+#', 'pertes.constat'],
        ['#^/api/gestion/pertes/constats$#', 'pertes.constats'],
        ['#^/api/gestion/pertes/regles(/|$)#', 'pertes.regles'],
        ['#^/api/gestion/pertes/reglages(/|$)#', 'pertes.reglages'],
        ['#^/api/gestion/pertes/exercices(/|$)#', 'pertes.exercices'],
        ['#^/api/gestion/pertes/(pieces|puces)(/|$)#', 'pertes.pieces'],
        ['#^/api/gestion/pertes/(installation|materiel)(/|$)#', 'pertes.installation'],
        ['#^/api/gestion/pertes/(boitiers|postes|sante)(/|$)#', 'pertes.boitiers'],
        ['#^/api/gestion/pertes(/|$)#', 'pertes.home'],
        // --- gestion
        ['#^/api/gestion/codes-barres(/|$)#', 'codes-barres'],
        ['#^/api/gestion/(accueil|journee|ecrans)(/|$)#', 'gestion.home'],
        ['#^/api/gestion/articles(/|$)#', 'articles'],
        ['#^/api/gestion/campagnes(/|$)#', 'publicite'],
        ['#^/api/gestion/conseils(/|$)#', 'conseils'],
        ['#^/api/gestion/(console|ia)(/|$)#', 'console'],
        ['#^/api/gestion/conversations(/|$)#', 'conversations'],
        ['#^/api/gestion/documents(/|$)#', 'documents.scan'],
        ['#^/api/gestion/exports(/|$)#', 'commandes'],
        ['#^/api/gestion/frais-fixes(/|$)#', 'frais-fixes'],
        ['#^/api/gestion/lexique(/|$)#', 'lexique'],
        ['#^/api/gestion/locations/(grilles|promotions)(/|$)#', 'locations.prix'],
        ['#^/api/gestion/locations(/|$)#', 'locations'],
        ['#^/api/gestion/notifications(/|$)#', 'notifications'],
        ['#^/api/gestion/preparation(/|$)#', 'preparation'],
        ['#^/api/gestion/prix(/|$)#', 'prix'],
        ['#^/api/gestion/promotions(/|$)#', 'promotions'],
        ['#^/api/gestion/rapports(/|$)#', 'rapports'],
        ['#^/api/gestion/reglages(/|$)#', 'reglages'],
        ['#^/api/gestion/reprise(/|$)#', 'reprise'],
        ['#^/api/gestion/sequences(/|$)#', 'sequences'],
        ['#^/api/gestion/statistiques/annee(/|$)#', 'statistiques.annee'],
        ['#^/api/gestion/statistiques(/|$)#', 'statistiques'],
        ['#^/api/gestion/tresorerie/rapprochements(/|$)#', 'tresorerie.rapprochements'],
        ['#^/api/gestion/tresorerie(/|$)#', 'tresorerie'],
        ['#^/api/gestion/voyages(/|$)#', 'voyages'],
        ['#^/api/periodes/[^/]+/budget(/|$)#', 'periode.budget'],
        ['#^/api/periodes/[^/]+/simulation(/|$)#', 'periode.simulation'],
        ['#^/api/periodes/[^/]+/resultat$#', 'periode'],
        ['#^/api/periodes(/|$)#', 'periodes'],
        ['#^/api/collections/[^/]+/commandes$#', 'commandes'],
        ['#^/api/collections/[^/]+/notes-de-frais$#', 'notes-de-frais'],
        ['#^/api/collections/[^/]+/frais-de-port$#', 'frais-de-port'],
        ['#^/api/collections/[^/]+/resultat$#', 'periode'],
        ['#^/api/(collections|createcollections)(/|$)#', 'periodes'],
        ['#^/api/dashboard/collection/#', 'periode'],
        ['#^/api/dashboard/commande/#', 'commandes'],
        ['#^/api/dashboard(/|$)#', 'periodes'],
        ['#^/api/commandes/lignes/a-recevoir$#', 'a-recevoir'],
        ['#^/api/commandes/[^/]+/frais-de-port$#', 'frais-de-port'],
        ['#^/api/commandes(/|$)#', 'commandes'],
        ['#^/api/(fournisseurs|type-fournisseurs)(/|$)#', 'fournisseurs'],
        ['#^/api/(notes-de-frais|type-note-de-frais|frais)(/|$)#', 'notes-de-frais'],
        ['#^/api/(transporteurs|carrier)(/|$)#', 'transporteurs'],
        ['#^/api/(statistiques|stock-value|stock-evolution|taxes)(/|$)#', 'statistiques'],
        ['#^/api/payments/statistics(/|$)#', 'statistiques'],
        ['#^/api/payments$#', '@compte'],
        // --- construction : catalogue
        ['#^/api/products/ai(/|$)#', 'catalogue.ia'],
        ['#^/api/products/[^/]+/reviews(/|$)#', ['GET' => '@public', '*' => '@compte']],
        ['#^/api/products(\.[^/]+)?$#', 'catalogue'],
        // (ressources d'API Platform : le gabarit de leurs routes porte le format, `.{_format}`)
        ['#^/api/(categories|vehicle_products)([./]|$)#', ['GET' => '@public', '*' => 'catalogue']],
        ['#^/api/vehicles(\.[^/]+)?$#', ['GET' => '@public', '*' => 'catalogue']],
        ['#^/api/products(/|$)#', ['GET' => '@public', '*' => 'catalogue']],
        ['#^/api/(product-variants|customization-combinations|customization-options|customization-values)(/|$)#', 'catalogue'],
        ['#^/api/(colors|sizes)$#', ['GET' => '@public', '*' => 'catalogue']],
        ['#^/api/media(/|$)#', 'medias'],
        // --- construction : pages, apparence, réglages du site
        ['#^/api/landingpage-ai(/|$)#', 'pages.ia'],
        ['#^/api/landingpage-audit(/|$)#', 'site.journal'],
        ['#^/api/site-content(/|$)#', 'pages'],
        ['#^/api/[^/]+-site-models(/|$)#', 'pages'],
        ['#^/api/(landingpage-settings|boutique-settings)$#', ['GET' => '@public', '*' => 'pages']],
        ['#^/api/admin-settings(/|$)#', ['GET' => '@public', '*' => 'apparence']],
        ['#^/api/site/charter(/|$)#', 'apparence'],
        ['#^/api/(bannieres|emplois|features|multiliens|presentation-groups|recherches|service-families|service-offers|team|videos|categories-marque|marques)(/|$)#', ['GET' => '@public', '*' => 'pages.contenus']],
        ['#^/api/\{resource\}/#', 'pages.contenus'],
        ['#^/videos(/|$)#', ['GET' => '@ancien', '*' => 'pages.contenus']],
        ['#^/api/entreprise(/|$)#', ['GET' => '@public', '*' => 'site.reglages']],
        ['#^/api/site/assistant(/|$)#', 'site.assistant'],
        ['#^/api/site/domain(/|$)#', 'site.domaine'],
        ['#^/api/site/features(/|$)#', 'site.modules'],
        ['#^/api/site/help(/|$)#', 'site.aide'],
        ['#^/api/site/imports/archive/orders$#', 'imports.commandes'],
        ['#^/api/site/imports/[^/]+/(customers|invitations)$#', 'imports.clients'],
        ['#^/api/site/imports(/|$)#', 'imports'],
        ['#^/api/site/storefront(/preview)?$#', '@public'],
        ['#^/api/site/storefront/#', 'site.ouverture'],
        ['#^/api/(site/readiness|site/shipping|app-keys)(/|$)#', 'site.reglages'],
        // --- clients : ce que le site a reçu
        ['#^/api/contacts(/create)?$#', ['POST' => '@public', '*' => 'clients.demandes']],
        ['#^/api/(candidatures|form-submissions)$#', ['POST' => '@public', '*' => 'clients.demandes']],
        ['#^/api/newsletter/subscribe$#', '@public'],
        ['#^/api/(contacts|candidatures|form-submissions|newsletter)(/|$)#', 'clients.demandes'],
        // --- site public : lectures et envois sans écran d'administration
        ['#^/api/(productsid|category|currencies|customization|components-config|embeds|explore-cards|homeslider|landingpage|offers|presentations|shared-media|subscription-plans|vehicles|baniere-statiques|booking|reservations|cart|contact|financement|Carrier|order-statuses|stripe-config|styles|social-networks|r)(/|$)#', '@public'],
        ['#^/api/stripe/create-intent$#', '@public'],
        ['#^/api/shipping/(rates|summary)$#', '@public'],
        ['#^/api/order/create-guest$#', '@public'],
        ['#^/api/orders/[^/]+$#', '@public'],
        ['#^/api/(shipping|order|orders)(/|$)#', '@compte'],
        // --- EasyAdmin : les pages qui ont leur propre adresse (les écrans CRUD passent par `/admin`, voir ADMIN_CRUDS)
        ['#^/admin/(redis|workers|technique|super-admin|plateforme)(/|$)#', 'admin.maintenance'],
        ['#^/admin/mon-site/assistant(/|$)#', 'admin.assistant'],
        ['#^/admin/(mon-site|ouverture-du-site|configuration|aide|mobile-theme|short-code|contenu-exemple|app-keys|acces-plateforme)(/|$)#', 'admin.entreprise'],
        ['#^/admin/(site|apercu)(/|$)#', 'admin.vitrine'],
        ['#^/admin/(produits|product|product-variant|barcode-management|print-barcode|pdf)(/|$)#', 'admin.catalogue'],
        ['#^/admin/livraison(/|$)#', 'admin.livraison'],
        ['#^/admin/location(/|$)#', 'admin.location'],
        ['#^/admin/(commandes|orders)(/|$)#', 'admin.commandes'],
        ['#^/admin/stripe(/|$)#', 'admin.paiement'],
        ['#^/admin/(caisse|pos)(/|$)#', 'admin.caisse'],
        ['#^/admin/controles(/|$)#', 'admin.controles'],
        ['#^/admin/(clients|users)(/|$)#', 'admin.utilisateurs'],
        ['#^/admin/gestion(/|$)#', 'admin.achats'],
        ['#^/admin/memoires(/|$)#', 'admin.memoires'],
        ['#^/admin/?$#', 'admin.accueil'],
        // --- anciennes pages de la boutique
        ['#^/(account|adress|booking-setup|cart|cgu|checkout|contact|create-checkout-session|logout|password-reset|product|register|shared-media|shop|stripe|stripe-payment-cancel|stripe-payment-succes|verify)(/|$)#', '@ancien'],
        ['#^/$#', '@ancien'],
    ];

    /**
     * Les écrans CRUD d'EasyAdmin, qui passent tous par l'adresse `/admin` : nom court du contrôleur => écran du registre.
     *
     * @var array<string, string>
     */
    public const ADMIN_CRUDS = [
        // entreprise et réglages
        'EntrepriseCrudController' => 'admin.entreprise', 'AddressEntrepriseCrudController' => 'admin.entreprise', 'SocialNetworkCrudController' => 'admin.entreprise',
        'AppProfileCrudController' => 'admin.entreprise', 'TeamCrudController' => 'admin.entreprise', 'EmailConfigurationCrudController' => 'admin.entreprise',
        'GooglePlacesConfigCrudController' => 'admin.entreprise', 'SquareConfigCrudController' => 'admin.entreprise',
        // site vitrine
        'BanniereCrudController' => 'admin.vitrine', 'EmploiCrudController' => 'admin.vitrine', 'MarqueCrudController' => 'admin.vitrine', 'CategorieMarqueCrudController' => 'admin.vitrine',
        'MultilienCrudController' => 'admin.vitrine', 'RechercheCrudController' => 'admin.vitrine', 'ServiceOfferCrudController' => 'admin.vitrine', 'ServiceFamilyCrudController' => 'admin.vitrine',
        'VideoCrudController' => 'admin.vitrine', 'EmbedCrudController' => 'admin.vitrine', 'BaniereStatiqueCrudController' => 'admin.vitrine', 'PresentationCrudController' => 'admin.vitrine',
        'PresentationGroupCrudController' => 'admin.vitrine',
        // boutique : accueil et pages
        'HomeSliderCrudController' => 'admin.boutique-pages', 'FeatureCrudController' => 'admin.boutique-pages', 'ExploreCardCrudController' => 'admin.boutique-pages',
        'SubscriptionPlanCrudController' => 'admin.boutique-pages',
        'LandingPageSettingCrudController' => 'admin.pages-avancees', 'BoutiqueSettingCrudController' => 'admin.pages-avancees',
        'AiUsageCrudController' => 'admin.ia', 'AiCreditSettingCrudController' => 'admin.ia',
        'SharedMediaCrudController' => 'admin.medias',
        // catalogue
        'ProductCrudController' => 'admin.catalogue', 'ProductVariantCrudController' => 'admin.catalogue', 'ColorCrudController' => 'admin.catalogue', 'StyleCrudController' => 'admin.catalogue',
        'SizeCrudController' => 'admin.catalogue', 'CategoriesCrudController' => 'admin.catalogue', 'ProductOptionCrudController' => 'admin.catalogue',
        'ProductOptionValueCrudController' => 'admin.catalogue', 'ProductCustomizationImageCrudController' => 'admin.catalogue', 'ProductTypeCrudController' => 'admin.catalogue',
        'SaleUnitCrudController' => 'admin.catalogue', 'ProductPictureCrudController' => 'admin.catalogue', 'VehicleProductCrudController' => 'admin.catalogue',
        'VehicleCrudController' => 'admin.catalogue', 'ReviewSettingCrudController' => 'admin.catalogue',
        // livraison, taxes, location : réglages
        'CarrierCrudController' => 'admin.livraison', 'ProductShippingCrudController' => 'admin.livraison', 'PackagingTypeCrudController' => 'admin.livraison',
        'ShippingClassCrudController' => 'admin.livraison', 'EasyPostConfigurationCrudController' => 'admin.livraison',
        'TaxCrudController' => 'admin.taxes',
        'BookingConfigurationCrudController' => 'admin.location', 'RentalPackCrudController' => 'admin.location',
        // référentiels des commandes : des listes de réglage, aucune vente
        'StatusCommandeCrudController' => 'admin.commandes-reglages', 'OrderSourceCrudController' => 'admin.commandes-reglages', 'OrderTypeCrudController' => 'admin.commandes-reglages',
        'PaymentTypeCrudController' => 'admin.commandes-reglages', 'StatusPaymentCrudController' => 'admin.commandes-reglages', 'PaymentMethodCrudController' => 'admin.commandes-reglages',
        'CurrencyCrudController' => 'admin.commandes-reglages',
        // ventes
        'OrderCrudController' => 'admin.commandes', 'OrderAllCrudController' => 'admin.commandes', 'OrderItemsCrudController' => 'admin.commandes', 'CartCrudController' => 'admin.commandes',
        'PaymentsCrudController' => 'admin.commandes', 'OrderTaxCrudController' => 'admin.commandes',
        'StripeConfigCrudController' => 'admin.paiement',
        'ShippingOrderCrudController' => 'admin.expeditions', 'ParcelCrudController' => 'admin.expeditions', 'ShippingLabelCrudController' => 'admin.expeditions',
        'SubscriptionCrudController' => 'admin.abonnements',
        'MovementTypeCrudController' => 'admin.stock', 'InventoryMovementsCrudController' => 'admin.stock',
        'CashRegisterCrudController' => 'admin.caisse', 'PosFavoriteCrudController' => 'admin.caisse', 'CaisseCrudController' => 'admin.caisse', 'CashRegisterReportCrudController' => 'admin.caisse',
        'PosAuditLogCrudController' => 'admin.caisse', 'TransactionCaisseCrudController' => 'admin.caisse', 'TransactionTypeCrudController' => 'admin.caisse',
        'CashDetailsCrudController' => 'admin.caisse', 'TypeCashCrudController' => 'admin.caisse',
        // clients
        'ReservationCrudController' => 'admin.clients', 'BookingCrudController' => 'admin.clients', 'FormSubmissionCrudController' => 'admin.clients', 'ContactCrudController' => 'admin.clients',
        'CandidatureCrudController' => 'admin.clients', 'NewsletterSubscriberCrudController' => 'admin.clients', 'ReviewCrudController' => 'admin.clients',
        'UserCrudController' => 'admin.utilisateurs', 'AdressCrudController' => 'admin.utilisateurs',
        // gestion (achats)
        'CollectionsCrudController' => 'admin.achats', 'CommandeCrudController' => 'admin.achats', 'NoteDeFraisCrudController' => 'admin.achats', 'FournisseurCrudController' => 'admin.achats',
        'TypeFournisseurCrudController' => 'admin.achats', 'TransporteurCrudController' => 'admin.achats', 'FraisDePortCrudController' => 'admin.achats',
        'CollectionPictureCrudController' => 'admin.achats', 'TypeNoteDeFraisCrudController' => 'admin.achats',
        // contenus personnels
        'MemoireQuestionCrudController' => 'admin.memoires', 'BookCrudController' => 'admin.memoires', 'ChapterCrudController' => 'admin.memoires', 'BookPrintOrderCrudController' => 'admin.memoires',
        'DiagnosticQuestionCrudController' => 'admin.boussole', 'CertificationReferentialCrudController' => 'admin.boussole', 'SubsidyProgramCrudController' => 'admin.boussole',
        'CertificationRecommendationCrudController' => 'admin.boussole', 'OddMappingCrudController' => 'admin.boussole',
    ];

    /**
     * LES CHAMPS SENSIBLES des écrans de construction (inventaire du 16/11/2026, `docs/ecrans-et-contextes.md` § 5). Un écran de
     * construction peut rendre, au milieu du reste, ce qu'une agence ne doit jamais lire : le coût d'achat, le coefficient, la marge,
     * un fournisseur, une donnée de client, un chiffre de vente. Ce lot les NOMME ; la garde d'AG-3b les retirera des réponses du mode
     * agence (`fields` : chemin du champ dans la réponse), et fermera ce qui ne se filtre pas (`closed`).
     * Les quantités en stock ne sont pas sensibles.
     *
     * @var array<string, array{fields?: list<string>, closed?: list<string>, why: string}>
     */
    public const SENSITIVE = [
        'catalogue' => ['fields' => ['purchasePrice', 'coefficientMultiplier', 'commande', 'uses.sales', 'uses.purchaseLines', 'uses.bookings'],
            'why' => 'coût d\'achat, coefficient et commande fournisseur d\'un article ; nombres de ventes, de lignes d\'achat et de locations dans le refus de suppression (PRODUCT_IN_USE)'],
        'admin.catalogue' => ['fields' => ['purchasePrice', 'coefficientMultiplier', 'commande'], 'why' => 'colonnes « Prix d\'achat », « Coefficient » et « Dernière commande fournisseur » de l\'écran Produits'],
        'admin.accueil' => ['fields' => ['totalSales', 'totalOrders', 'totalCustomers', 'recentOrders', 'charts'], 'closed' => ['console flottante de l\'assistant'],
            'why' => 'la page d\'accueil d\'EasyAdmin est un tableau de ventes : totaux, courbes, dernières commandes avec nom, courriel et montant ; sa console flottante est l\'assistant du site'],
        'admin.entreprise' => ['fields' => ['cardPaymentsCount', 'figures'], 'closed' => ['création d\'une clé pour les applications admin et pos'],
            'why' => 'la page Configuration compte les paiements par carte encaissés ; « Mon site » reprend les chiffres des étapes de la gestion ; une clé d\'application active la gestion ou la caisse'],
        'admin.ia' => ['fields' => ['promptExcerpt'], 'why' => 'l\'historique des demandes garde le début des questions posées à la console de gestion'],
        'site.reglages' => ['fields' => ['charges', 'figures'], 'closed' => ['POST /api/app-keys pour les applications admin et pos'],
            'why' => 'achats d\'étiquettes par commande (numéro, référence, suivi, montant) ; nombres d\'articles sans coût, de fournisseurs et de frais fixes des étapes de la gestion ; une clé d\'application active la gestion ou la caisse'],
        'pages.ia' => ['fields' => ['history[].promptExcerpt'], 'why' => 'l\'usage de l\'IA garde le début des questions posées à la console de gestion'],
        'imports' => ['fields' => ['summary.orders', 'report.orders', 'result.cancel.kept[].reason'], 'closed' => ['kind=customers', 'kind=orders'],
            'why' => 'les imports de clients et de commandes passent par les mêmes routes que l\'import du catalogue : leur fiche est nominative ou chiffrée ; la liste mêle les natures ; l\'annulation dit quels articles ont été vendus'],
        // pour TOUS, pas seulement pour une agence : les fiches produit publiques rendent le coût à un visiteur sans connexion
        '@public' => ['fields' => ['purchasePrice', 'coefficientMultiplier'], 'why' => 'fiches produit publiques (/api/products/{id}, /by-slug, /by-category, /api/productsid) : coût et coefficient lisibles sans connexion ; à retirer pour tous'],
    ];

    /** @return array{family: string, module: ?string, roles: list<string>, surface: string}|null */
    public static function screen(string $code): ?array
    {
        $screen = self::SCREENS[$code] ?? null;

        return $screen === null ? null : $screen + ['roles' => self::ADMIN, 'surface' => 'web'];
    }

    /**
     * L'écran (ou la liste hors écran) auquel appartient une route ; null : la route n'est rangée nulle part.
     *
     * @param string $path le chemin décodé de la requête (`RequestPath::of`), ou le gabarit d'une route
     */
    public static function classify(string $method, string $path): ?string
    {
        $method = strtoupper($method);
        foreach (self::ROUTES as [$pattern, $target]) {
            if (preg_match($pattern, $path) === 1) {
                return is_array($target) ? ($target[$method] ?? $target['*'] ?? null) : $target;
            }
        }

        return null;
    }

    /** La famille d'une cible : celle de l'écran ; null pour une liste hors écran ou une cible inconnue */
    public static function familyOf(string $target): ?string
    {
        return self::SCREENS[$target]['family'] ?? null;
    }

    /** L'écran d'un contrôleur CRUD d'EasyAdmin (nom complet ou nom court de la classe) */
    public static function adminCrud(string $controller): ?string
    {
        $short = ($pos = strrpos($controller, '\\')) === false ? $controller : substr($controller, $pos + 1);

        return self::ADMIN_CRUDS[$short] ?? null;
    }

    /**
     * Les codes des écrans qu'un menu peut montrer : famille ouverte au mode, module actif sur le site, rôle porté par le compte.
     *
     * @param list<string> $families les familles ouvertes au mode
     * @param array<string, bool> $modules clé de module => actif sur ce site
     * @param callable(string): bool $hasRole le compte porte-t-il ce rôle (hiérarchie comprise) ?
     * @return list<string>
     */
    public static function visible(array $families, array $modules, callable $hasRole): array
    {
        $codes = [];
        foreach (self::SCREENS as $code => $screen) {
            if (!in_array($screen['family'], $families, true)) {
                continue;
            }
            if ($screen['module'] !== null && !($modules[$screen['module']] ?? false)) {
                continue;
            }
            foreach ($screen['roles'] ?? self::ADMIN as $role) {
                if ($hasRole($role)) {
                    $codes[] = $code;
                    break;
                }
            }
        }

        return $codes;
    }
}
