<?php

namespace App\Cache;

use App\Entity\AddressEntreprise;
use App\Entity\AdminSettings;
use App\Entity\Adress;
use App\Entity\AiCreditSetting;
use App\Entity\AiJob;
use App\Entity\AiUsage;
use App\Entity\BaniereStatique;
use App\Entity\BaniereStatiqueTranslation;
use App\Entity\Banniere;
use App\Entity\BanniereTranslation;
use App\Entity\Booking;
use App\Entity\BookingConfiguration;
use App\Entity\BoutiqueSetting;
use App\Entity\Caisse;
use App\Entity\Candidature;
use App\Entity\Carrier;
use App\Entity\CarrierTranslation;
use App\Entity\Cart;
use App\Entity\CartDetails;
use App\Entity\CashDetails;
use App\Entity\CashRegister;
use App\Entity\CashRegisterReport;
use App\Entity\Categories;
use App\Entity\CategoriesTranslation;
use App\Entity\CategorieMarque;
use App\Entity\CategorieMarqueTranslation;
use App\Entity\CheckoutSession;
use App\Entity\CollectionPicture;
use App\Entity\Collections;
use App\Entity\Color;
use App\Entity\ColorTranslation;
use App\Entity\Commande;
use App\Entity\Contact;
use App\Entity\ContentAuditLog;
use App\Entity\EasyPostConfiguration;
use App\Entity\EmailConfiguration;
use App\Entity\EmailConfigurationTranslation;
use App\Entity\Emploi;
use App\Entity\EmploiTranslation;
use App\Entity\Entreprise;
use App\Entity\EntrepriseTranslation;
use App\Entity\ExploreCard;
use App\Entity\ExploreCardTranslation;
use App\Entity\Feature;
use App\Entity\FeatureTranslation;
use App\Entity\FormSubmission;
use App\Entity\Fournisseur;
use App\Entity\FraisDePort;
use App\Entity\GooglePlacesConfig;
use App\Entity\HomeSlider;
use App\Entity\HomeSliderTranslation;
use App\Entity\InventoryMovements;
use App\Entity\LandingPageSetting;
use App\Entity\LandingSiteModel;
use App\Entity\Marque;
use App\Entity\MovementType;
use App\Entity\Multilien;
use App\Entity\NewsletterSubscriber;
use App\Entity\NoteDeFrais;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\OrderTypeTranslation;
use App\Entity\OtpCode;
use App\Entity\PackagingType;
use App\Entity\Parcel;
use App\Entity\PaymentMethod;
use App\Entity\PaymentMethodTranslation;
use App\Entity\Payments;
use App\Entity\PaymentType;
use App\Entity\PosApproval;
use App\Entity\PosAuditLog;
use App\Entity\PosCustomer;
use App\Entity\PosCustomerConsent;
use App\Entity\PosFavorite;
use App\Entity\PosGiftCard;
use App\Entity\PosGiftCardTransaction;
use App\Entity\PosHealthAlert;
use App\Entity\PosIdempotencyKey;
use App\Entity\PosLoyaltyTransaction;
use App\Entity\PosManufacturerCoupon;
use App\Entity\PosOfflinePack;
use App\Entity\PosOfflineSale;
use App\Entity\PosOfflineSync;
use App\Entity\PosReceipt;
use App\Entity\PosReturn;
use App\Entity\PosTerminalPayment;
use App\Entity\PosUserPin;
use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Entity\PresentationGroupTranslation;
use App\Entity\PresentationTranslation;
use App\Entity\Product;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOption;
use App\Entity\ProductOptionTranslation;
use App\Entity\ProductOptionValue;
use App\Entity\ProductOptionValueTranslation;
use App\Entity\ProductPicture;
use App\Entity\ProductShipping;
use App\Entity\ProductTranslation;
use App\Entity\ProductType;
use App\Entity\ProductVariant;
use App\Entity\Recherche;
use App\Entity\RechercheTranslation;
use App\Entity\AppProfile;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use App\Entity\RelatedProduct;
use App\Entity\RentalPack;
use App\Entity\RentalPackTranslation;
use App\Entity\Reservation;
use App\Entity\ResetPasswordRequest;
use App\Entity\ReviewSetting;
use App\Entity\ReviewsProduct;
use App\Entity\SaleUnit;
use App\Entity\SearchProduct;
use App\Entity\ServiceFamily;
use App\Entity\ServiceFamilyTranslation;
use App\Entity\ServiceOffer;
use App\Entity\ServiceOfferTranslation;
use App\Entity\SharedMedia;
use App\Entity\ShippingClass;
use App\Entity\ShippingLabel;
use App\Entity\ShippingOrder;
use App\Entity\Size;
use App\Entity\SizeTranslation;
use App\Entity\SocialNetwork;
use App\Entity\SquareConfig;
use App\Entity\StatusCommande;
use App\Entity\StatusCommandeTranslation;
use App\Entity\StatusPayment;
use App\Entity\StatusPaymentTranslation;
use App\Entity\StripeConfig;
use App\Entity\Style;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\SyncJob;
use App\Entity\Tax;
use App\Entity\Team;
use App\Entity\TeamTranslation;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Entity\Transporteur;
use App\Entity\TypeCash;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleProduct;
use App\Entity\VehicleTranslation;
use App\Entity\Video;
use App\Entity\VideoTranslation;

/**
 * Registre des étiquettes du cache applicatif (chantier « invalidation ciblée », lot 1, 28/10/2026).
 *
 * Une seule source de vérité pour les deux côtés : les lectures (TenantCacheService::get, étiquettes listTags() et
 * itemTags()) et les écritures (CacheInvalidationSubscriber, forEntity()). Avant le 26/10/2026, trois conventions de
 * noms cohabitaient et une modification restait une heure en cache ; depuis, toute écriture vide tout le site. Ce
 * registre permet de ne vider que le composant écrit, composant par composant (un lot par module) : une entité absente
 * du registre vide encore tout le site (filet de sécurité, journalisé), comme le 26/10.
 *
 * Trois niveaux d'étiquette par composant, préfixés par la base du site dans TenantCacheService::componentTag() :
 * - `<composant>` : tout le composant, listes et fiches, vidé seulement à la demande (TenantCacheService::invalidateComponents) ;
 * - `<composant>.list` : les listes, vidées par toute écriture d'une entité du composant ;
 * - `<composant>.item.<id>` : une fiche, vidée par l'écriture de cette entité ou d'un enfant (traduction, image).
 * Une lecture de liste porte listTags(), une lecture de fiche itemTags() ; une lecture qui agrège plusieurs composants
 * porte les étiquettes de chacun (un groupe de présentations porte aussi listTags(PRESENTATIONS) ; une liste de produits
 * porte productListTags()).
 */
final class CacheTags
{
    // Landing Page (lot 2)
    public const PRESENTATIONS = 'presentations';
    public const PRESENTATION_GROUPS = 'presentation_groups';
    public const BANNIERES = 'bannieres';
    public const BANNIERES_STATIQUES = 'bannieres_statiques';
    public const HOMESLIDER = 'homeslider';
    public const FEATURES = 'features';
    public const EXPLORE_CARDS = 'explore_cards';
    public const VIDEOS = 'videos';
    public const TEAM = 'team';
    public const RECHERCHES = 'recherches';
    public const MULTILIENS = 'multiliens';
    /** Marques et leurs catégories : un seul composant, chaque lecture mêle les deux */
    public const MARQUES = 'marques';
    public const EMPLOIS = 'emplois';
    public const SERVICE_OFFERS = 'service_offers';
    /** Familles de prestations : lues dans chaque prestation (familyId), les lectures de prestations les portent */
    public const SERVICE_FAMILIES = 'service_families';
    public const VEHICLES = 'vehicles';
    public const LANDING_SETTINGS = 'landing_settings';

    // Boutique (lot 3)
    /** Produits et leurs enfants (traductions, variantes et stock, images, liaisons, configuration de réservation) */
    public const PRODUCTS = 'products';
    public const CATEGORIES = 'categories';
    public const COLORS = 'colors';
    public const SIZES = 'sizes';
    public const STYLES = 'styles';
    public const CARRIERS = 'carriers';
    public const TRANSPORTEURS = 'transporteurs';
    public const BOUTIQUE_SETTINGS = 'boutique_settings';
    /**
     * Référentiels lus dans chaque produit (unités de vente, taxes, classes d'expédition, options de personnalisation,
     * types, forfaits de location, formules d'abonnement, réglages des avis) : rares à écrire, une écriture vide toutes
     * les listes et fiches de produits.
     */
    public const PRODUCT_REFERENTIALS = 'product_referentials';

    // Commun (lot 3)
    public const ENTREPRISE = 'entreprise';
    public const ADMIN_SETTINGS = 'admin_settings';

    // Commandes, paiements et achats (lot 4)
    /** Commandes de vente (web, caisse) et tout ce que leur liste affiche : lignes, reçus, client, poste, expédition, statuts */
    public const ORDERS = 'orders';
    public const PAYMENTS = 'payments';
    /** Achats : commandes fournisseurs (module historique) */
    public const COMMANDES = 'commandes';
    public const COLLECTIONS = 'collections';
    public const FOURNISSEURS = 'fournisseurs';
    public const NOTES_DE_FRAIS = 'notes_de_frais';

    /**
     * Entité → composants qu'une écriture vide (liste du composant et fiche de l'entité).
     *
     * @var array<class-string, list<string>>
     */
    public const ENTITY_TAGS = [
        Presentation::class => [self::PRESENTATIONS],
        PresentationGroup::class => [self::PRESENTATION_GROUPS],
        Banniere::class => [self::BANNIERES],
        BaniereStatique::class => [self::BANNIERES_STATIQUES],
        HomeSlider::class => [self::HOMESLIDER],
        Feature::class => [self::FEATURES],
        ExploreCard::class => [self::EXPLORE_CARDS],
        Video::class => [self::VIDEOS],
        Team::class => [self::TEAM],
        Recherche::class => [self::RECHERCHES],
        Multilien::class => [self::MULTILIENS],
        Marque::class => [self::MARQUES],
        CategorieMarque::class => [self::MARQUES],
        Emploi::class => [self::EMPLOIS],
        ServiceOffer::class => [self::SERVICE_OFFERS],
        ServiceFamily::class => [self::SERVICE_FAMILIES],
        Vehicle::class => [self::VEHICLES],
        // un véhicule lié à un produit apparaît dans le carrousel et dans la liste complète des produits
        VehicleProduct::class => [self::VEHICLES, self::PRODUCTS],
        LandingPageSetting::class => [self::LANDING_SETTINGS],

        Product::class => [self::PRODUCTS],
        Categories::class => [self::CATEGORIES],
        Color::class => [self::COLORS],
        Size::class => [self::SIZES],
        Style::class => [self::STYLES],
        Carrier::class => [self::CARRIERS],
        Transporteur::class => [self::TRANSPORTEURS],
        BoutiqueSetting::class => [self::BOUTIQUE_SETTINGS],
        SaleUnit::class => [self::PRODUCT_REFERENTIALS],
        Tax::class => [self::PRODUCT_REFERENTIALS],
        ShippingClass::class => [self::PRODUCT_REFERENTIALS],
        ProductOption::class => [self::PRODUCT_REFERENTIALS],
        ProductType::class => [self::PRODUCT_REFERENTIALS],
        RentalPack::class => [self::PRODUCT_REFERENTIALS],
        SubscriptionPlan::class => [self::PRODUCT_REFERENTIALS],
        ReviewSetting::class => [self::PRODUCT_REFERENTIALS],

        Entreprise::class => [self::ENTREPRISE],
        AdminSettings::class => [self::ADMIN_SETTINGS],

        Order::class => [self::ORDERS],
        OrderItems::class => [self::ORDERS],
        OrderTax::class => [self::ORDERS],
        // un paiement est lu dans la liste des paiements et dans celle des commandes (état de paiement)
        Payments::class => [self::PAYMENTS, self::ORDERS],
        ShippingOrder::class => [self::ORDERS],
        ShippingLabel::class => [self::ORDERS],
        // ce que la liste des commandes affiche sur la commande : client (web ou caisse), adresse, poste de caisse
        User::class => [self::ORDERS],
        Adress::class => [self::ORDERS],
        PosCustomer::class => [self::ORDERS],
        CashRegister::class => [self::ORDERS],
        // référentiels des commandes et des paiements (statuts, types, moyens), traductions comprises
        StatusCommande::class => [self::ORDERS, self::PAYMENTS],
        StatusCommandeTranslation::class => [self::ORDERS, self::PAYMENTS],
        StatusPayment::class => [self::ORDERS, self::PAYMENTS],
        StatusPaymentTranslation::class => [self::ORDERS, self::PAYMENTS],
        OrderType::class => [self::ORDERS, self::PAYMENTS],
        OrderTypeTranslation::class => [self::ORDERS, self::PAYMENTS],
        OrderSource::class => [self::ORDERS, self::PAYMENTS],
        PaymentMethod::class => [self::ORDERS, self::PAYMENTS],
        PaymentMethodTranslation::class => [self::ORDERS, self::PAYMENTS],
        PaymentType::class => [self::ORDERS, self::PAYMENTS],

        Commande::class => [self::COMMANDES],
        Collections::class => [self::COLLECTIONS],
        Fournisseur::class => [self::FOURNISSEURS],
        TypeFournisseur::class => [self::FOURNISSEURS],
        NoteDeFrais::class => [self::NOTES_DE_FRAIS],
        TypeNoteDeFrais::class => [self::NOTES_DE_FRAIS],
    ];

    /**
     * Entité enfant → méthode qui rend son parent (traduction, image, liaison) : l'écriture vide les étiquettes du parent.
     *
     * @var array<class-string, string>
     */
    public const PARENT = [
        PresentationTranslation::class => 'getPresentation',
        PresentationGroupTranslation::class => 'getPresentationGroup',
        BanniereTranslation::class => 'getBanniere',
        BaniereStatiqueTranslation::class => 'getBaniereStatique',
        HomeSliderTranslation::class => 'getHomeSlider',
        FeatureTranslation::class => 'getFeature',
        ExploreCardTranslation::class => 'getExploreCard',
        VideoTranslation::class => 'getVideo',
        TeamTranslation::class => 'getTeam',
        RechercheTranslation::class => 'getRecherche',
        CategorieMarqueTranslation::class => 'getCategorieMarque',
        EmploiTranslation::class => 'getEmploi',
        ServiceOfferTranslation::class => 'getServiceOffer',
        ServiceFamilyTranslation::class => 'getFamily',
        VehicleTranslation::class => 'getVehicle',

        ProductTranslation::class => 'getProduct',
        ProductVariant::class => 'getProduct',
        ProductPicture::class => 'getProduct',
        RelatedProduct::class => 'getProduct',
        ProductShipping::class => 'getProduct',
        BookingConfiguration::class => 'getProduct',
        ProductCustomizationImage::class => 'getProductVariant',
        CategoriesTranslation::class => 'getCategory',
        ColorTranslation::class => 'getColor',
        SizeTranslation::class => 'getSize',
        CarrierTranslation::class => 'getCarrier',
        ProductOptionTranslation::class => 'getProductOption',
        ProductOptionValue::class => 'getProductOption',
        ProductOptionValueTranslation::class => 'getProductOptionValue',
        RentalPackTranslation::class => 'getRentalPack',

        EntrepriseTranslation::class => 'getEntreprise',
        SocialNetwork::class => 'getEntreprise',
        AddressEntreprise::class => 'getEntreprise',

        PosReceipt::class => 'getOrder',
        Parcel::class => 'getShippingOrder',
        FraisDePort::class => 'getCommande',
        CollectionPicture::class => 'getCollections',
    ];

    /**
     * Entités qu'aucune réponse en cache ne lit : leur écriture ne vide rien. Jetons, codes, journaux, suivi de l'IA ;
     * contacts, candidatures, formulaires, inscriptions et réservations reçus des visiteurs (données personnelles,
     * listes d'administration lues en base) ; avis (leur moyenne est recopiée sur le produit, qui est alors écrit) ;
     * caisse (sessions, transactions, rapports, validations, PIN, cartes-cadeaux, fidélité, coupons, hors ligne,
     * terminal : lectures en base depuis le lot 4) ; paniers, configurations de paiement et d'expédition, mouvements de
     * stock (le stock lui-même est sur la variante, qui est écrite).
     *
     * @var list<class-string>
     */
    public const NO_CACHE_READ = [
        // Jeton de rafraîchissement JWT : le bundle Gesdinet persiste sa propre classe, pas App\Entity\RefreshToken
        // (journal du 28/10/2026 : chaque connexion vidait tout le cache du site), d'où l'interface du bundle
        RefreshTokenInterface::class,
        AppProfile::class, // profils d'application (thème, image de marque) : aucune lecture en cache applicatif
        OtpCode::class,
        ResetPasswordRequest::class,
        AiUsage::class,
        AiJob::class,
        AiCreditSetting::class,
        ContentAuditLog::class,
        SyncJob::class,
        Contact::class,
        Candidature::class,
        FormSubmission::class,
        NewsletterSubscriber::class,
        Reservation::class,
        Booking::class,
        ReviewsProduct::class,
        Caisse::class,
        TransactionCaisse::class,
        CashDetails::class,
        CashRegisterReport::class,
        TypeCash::class,
        TransactionType::class,
        PosAuditLog::class,
        PosIdempotencyKey::class,
        PosApproval::class,
        PosCustomerConsent::class,
        PosFavorite::class,
        PosGiftCard::class,
        PosGiftCardTransaction::class,
        PosHealthAlert::class,
        PosLoyaltyTransaction::class,
        PosManufacturerCoupon::class,
        PosOfflinePack::class,
        PosOfflineSale::class,
        PosOfflineSync::class,
        PosReturn::class,
        PosTerminalPayment::class,
        PosUserPin::class,
        Cart::class,
        CartDetails::class,
        CheckoutSession::class,
        Subscription::class,
        StripeConfig::class,
        SquareConfig::class,
        EasyPostConfiguration::class,
        GooglePlacesConfig::class,
        EmailConfiguration::class,
        EmailConfigurationTranslation::class,
        InventoryMovements::class,
        MovementType::class,
        PackagingType::class,
        SearchProduct::class,
        LandingSiteModel::class,
    ];

    /**
     * Champs dont la seule modification ne change aucune réponse en cache (compteurs écrits pendant une lecture).
     *
     * @var array<class-string, list<string>>
     */
    public const IGNORED_FIELDS = [
        SharedMedia::class => ['downloadCount'],
        User::class => ['lastLoginAt'],
    ];

    /** @return list<string> étiquettes d'une lecture de liste */
    public static function listTags(string $component): array
    {
        return [$component, $component . '.list'];
    }

    /** @return list<string> étiquettes d'une lecture de fiche */
    public static function itemTags(string $component, int|string $id): array
    {
        return [$component, $component . '.item.' . $id];
    }

    /**
     * Étiquettes d'une liste de produits : produits et tout ce qu'une ligne de produit affiche (catégorie, couleurs et
     * tailles des variantes, style, réglages de la boutique, référentiels).
     *
     * @return list<string>
     */
    public static function productListTags(): array
    {
        return [...self::listTags(self::PRODUCTS), ...self::productDependencyTags()];
    }

    /** @return list<string> étiquettes d'une fiche produit : le produit lui-même et les mêmes dépendances */
    public static function productItemTags(int|string $productId): array
    {
        return [...self::itemTags(self::PRODUCTS, $productId), ...self::productDependencyTags()];
    }

    /** @return list<string> */
    private static function productDependencyTags(): array
    {
        return [
            ...self::listTags(self::CATEGORIES), ...self::listTags(self::COLORS), ...self::listTags(self::SIZES),
            ...self::listTags(self::STYLES), ...self::listTags(self::BOUTIQUE_SETTINGS), ...self::listTags(self::PRODUCT_REFERENTIALS),
        ];
    }

    /**
     * Étiquettes à vider après l'écriture d'une entité : [] si aucune lecture en cache ne la lit, null si elle n'est pas
     * déclarée (vider tout le site). Une entité créée n'a pas encore d'identifiant au flush : seule la liste est vidée,
     * aucune fiche ne peut la contenir.
     *
     * @return ?list<string>
     */
    public static function forEntity(object $entity): ?array
    {
        if (self::isOneOf($entity, self::NO_CACHE_READ)) {
            return [];
        }
        $root = self::root($entity);
        if ($root === null) {
            return null;
        }
        foreach (self::ENTITY_TAGS as $class => $components) {
            if (!$root instanceof $class) {
                continue;
            }
            $id = method_exists($root, 'getId') ? $root->getId() : null;
            $tags = [];
            foreach ($components as $component) {
                $tags[] = $component . '.list';
                if ($id !== null) {
                    $tags[] = $component . '.item.' . $id;
                }
            }

            return $tags;
        }

        return null;
    }

    /** @return list<string> champs dont la modification seule ne vide rien */
    public static function ignoredFields(object $entity): array
    {
        foreach (self::IGNORED_FIELDS as $class => $fields) {
            if ($entity instanceof $class) {
                return $fields;
            }
        }

        return [];
    }

    /** Remonte au parent déclaré (trois niveaux au plus) ; null si un parent manque (entité orpheline : filet) */
    private static function root(object $entity): ?object
    {
        for ($depth = 0; $depth < 3; $depth++) {
            $getter = null;
            foreach (self::PARENT as $class => $method) {
                if ($entity instanceof $class) {
                    $getter = $method;
                    break;
                }
            }
            if ($getter === null) {
                return $entity;
            }
            $parent = $entity->$getter();
            if (!is_object($parent)) {
                return null;
            }
            $entity = $parent;
        }

        return $entity;
    }

    /** @param list<class-string> $classes */
    private static function isOneOf(object $entity, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($entity instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
