<?php

namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Entity\Style;
use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\Color;
use App\Entity\Carrier;
use App\Entity\Contact;
use App\Entity\Product;
use App\Entity\Categories;
use App\Entity\HomeSlider;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\User;
use App\Entity\CollectionPicture;
use App\Entity\SquareConfig;
use App\Entity\NoteDeFrais;
use App\Entity\Fournisseur;
use App\Entity\Transporteur;
use App\Entity\FraisDePort;
use App\Entity\Adress;
use App\Entity\TransactionType;
use App\Entity\OrderItems;
use App\Entity\MovementType;
use App\Entity\PaymentMethod;
use App\Entity\OrderSource;
use App\Entity\StatusPayment;
use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TypeNoteDeFrais;
use App\Entity\TypeFournisseur;
use App\Entity\InventoryMovements;
use App\Entity\Payments;
use App\Entity\StatusCommande;
use App\Entity\Tax;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\AdminSettings;
use App\Entity\Entreprise;
use App\Entity\SocialNetwork;
use App\Entity\PaymentType;
use App\Entity\CashDetails;
use App\Entity\Denomination;
use App\Entity\TypeCash;
use App\Entity\Feature;
use App\Entity\ExploreCard;
use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use App\Entity\EasyPostConfiguration;
use App\Entity\ShippingClass;
use App\Entity\GooglePlacesConfig;
use App\Entity\ShippingLabel;
use App\Entity\ShippingOrder;
use App\Entity\Parcel;
use App\Entity\AddressEntreprise;
use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\LandingPageSetting;
use App\Entity\Presentation;
use App\Entity\ProductType;
use App\Entity\PresentationGroup;
use App\Entity\BaniereStatiqueTranslation;
use App\Entity\StripeConfig;
use App\Controller\Admin\StripeConfigCrudController;



use App\Controller\Admin\OrderAllCrudController;
use App\Controller\Admin\OrderCrudController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use App\Entity\EmailConfiguration;
use App\Entity\Team;
use App\Entity\Banniere;
use App\Entity\Emploi;
use App\Entity\Candidature;
use App\Entity\Marque;
use App\Entity\CategorieMarque;
use App\Entity\Multilien;
use App\Entity\Recherche;
use App\Entity\ServiceOffer;
use App\Entity\Video;
use App\Entity\Embed;
use App\Entity\BaniereStatique;
use App\Entity\NewsletterSubscriber;
use App\Entity\BookingConfiguration;
use App\Entity\Booking;
use App\Entity\RentalPack;
use App\Entity\SaleUnit;
use App\Entity\Vehicle;
use App\Controller\Admin\RentalPackCrudController;
use App\Controller\Admin\SaleUnitCrudController;
use App\Controller\Admin\VehicleCrudController;




class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/assets/Logo-Principal_GEM-PORTAL.png" style="max-height: 45px; width: auto;">');

    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        
        yield MenuItem::section('Synchronisation');
        yield MenuItem::linkToRoute(
            'Synchronisation GemSuite', 
            'fas fa-sync-alt', 
            'admin_sync_gemsuite' 
        );
        
        yield MenuItem::section('UI');
        yield MenuItem::linkToCrud('Home Slider', 'fas fa-images', HomeSlider::class);
        yield MenuItem::linkToCrud('Admin Settings', 'fas fa-cogs', AdminSettings::class);
        yield MenuItem::linkToCrud('Features', 'fa fa-star', Feature::class);
        yield MenuItem::linkToCrud('Explore Cards', 'fa fa-th-large', ExploreCard::class);

        yield MenuItem::section('Landing Page');


        yield MenuItem::linkToCrud('Bannières', 'fas fa-image', Banniere::class);
        yield MenuItem::linkToCrud('Offres d\'emploi', 'fas fa-briefcase', Emploi::class);
        yield MenuItem::linkToCrud('Candidatures', 'fas fa-file-alt', Candidature::class);
        yield MenuItem::linkToCrud('Marques', 'fas fa-tags', Marque::class);
        yield MenuItem::linkToCrud('Catégories de Marque', 'fas fa-sitemap', CategorieMarque::class);
        yield MenuItem::linkToCrud('Multiliens', 'fas fa-link', Multilien::class);
        yield MenuItem::linkToCrud('Section Recherche', 'fas fa-search', Recherche::class);
        yield MenuItem::linkToCrud('Offres de Service', 'fas fa-concierge-bell', ServiceOffer::class);
        yield MenuItem::linkToCrud('Vidéos', 'fas fa-video', Video::class);
        yield MenuItem::linkToCrud('Contenus Intégrés', 'fas fa-code', Embed::class);
        yield MenuItem::linkToCrud('Configuration', 'fas fa-cogs', LandingPageSetting::class);
        yield MenuItem::linkToCrud('Bannières Statiques', 'fas fa-image', BaniereStatique::class);
        yield MenuItem::linkToCrud('Présentations', 'fas fa-columns', Presentation::class);
        yield MenuItem::linkToCrud('Groupes de Présentation', 'fas fa-columns', PresentationGroup::class);



        yield MenuItem::section('Entreprise');
        yield MenuItem::linkToCrud('Entreprise', 'fa fa-building', Entreprise::class);
        yield MenuItem::linkToCrud('AddressEntreprise', 'fa fa-map-marker', AddressEntreprise::class);
        yield MenuItem::linkToCrud('Réseaux Sociaux', 'fa fa-share-alt', SocialNetwork::class);
        yield MenuItem::linkToCrud('Équipes', 'fa fa-users', Team::class);

        yield MenuItem::section('Livraison');
        yield MenuItem::linkToCrud('EasyPost Configuration', 'fa fa-cogs', EasyPostConfiguration::class);
        yield MenuItem::linkToCrud('Shipping Classes', 'fas fa-tags', ShippingClass::class);
        yield MenuItem::linkToCrud('ProductShipping', 'fas fa-truck', ProductShipping::class);
        yield MenuItem::linkToCrud('PackagingType', 'fas fa-box', PackagingType::class);
        yield MenuItem::linkToCrud('GooglePlacesConfig', 'fa fa-map-marker', GooglePlacesConfig::class)
            ->setController(GooglePlacesConfigCrudController::class);
        yield MenuItem::linkToCrud('Shipping Orders', 'fas fa-shipping-fast', ShippingOrder::class);
        yield MenuItem::linkToCrud('Parcels',         'fas fa-box',          Parcel::class);
        yield MenuItem::linkToCrud('Shipping Labels', 'fas fa-tag',          ShippingLabel::class);

        yield MenuItem::section('IIZIMANAGER');
        yield MenuItem::linkToCrud('Collections', 'fas fa-archive', Collections::class);
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-cart', Commande::class);
        yield MenuItem::linkToCrud('Notes de Frais', 'fas fa-receipt', NoteDeFrais::class);
        yield MenuItem::linkToCrud('Fournisseurs', 'fas fa-truck', Fournisseur::class);
        yield MenuItem::linkToCrud('Type Fournisseurs', 'fas fa-list', TypeFournisseur::class);
        yield MenuItem::linkToCrud('Transporteurs', 'fas fa-truck', Transporteur::class);
        yield MenuItem::linkToCrud('Frais de Port', 'fas fa-shipping-fast', FraisDePort::class);
        yield MenuItem::linkToCrud('ImageCollection', 'fas fa-images ', CollectionPicture::class);
        yield MenuItem::linkToCrud('Type note de frais', 'fas fa-tags', TypeNoteDeFrais::class);
        
        yield MenuItem::section('Mouvements User');
        yield MenuItem::linkToCrud('Adresse user', 'fas fa-shipping-fast', Adress::class);
        yield MenuItem::linkToCrud('Contact', 'fas fa-envelope', Contact::class);
        yield MenuItem::linkToCrud('Users', 'fas fa-user', User::class);
        yield MenuItem::linkToCrud('Email Configuration', 'fa fa-envelope', EmailConfiguration::class);
        yield MenuItem::linkToCrud('Newsletter', 'fa fa-envelope', NewsletterSubscriber::class);

        yield MenuItem::section('Mouvements stock');
        yield MenuItem::linkToCrud('Types de Mouvement', 'fas fa-exchange-alt', MovementType::class);
        yield MenuItem::linkToCrud('Inventory Movements', 'fas fa-dolly', InventoryMovements::class);

        yield MenuItem::section('Caisse');
        yield MenuItem::linkToCrud('Caisse', 'fas fa-cash-register', Caisse::class);
        yield MenuItem::linkToCrud('Transaction Caisse', 'fas fa-cash-register', TransactionCaisse::class)->setController(TransactionCaisseCrudController::class);
        yield MenuItem::linkToCrud('Transaction Type caisse', 'fas fa-exchange-alt', TransactionType::class);
        yield MenuItem::linkToCrud('Cash Details', 'fas fa-money-bill-wave', CashDetails::class)->setController(CashDetailsCrudController::class);
        yield MenuItem::linkToCrud('Type Cash', 'fas fa-money-bill-wave', TypeCash::class)->setController(TypeCashCrudController::class);

        yield MenuItem::section('Order');
        yield MenuItem::linkToCrud('Create order', 'fas fa-list', Order::class)
                ->setController(OrderAllCrudController::class);
        yield MenuItem::linkToCrud('Status Order', 'fas fa-tags', StatusCommande::class);
        yield MenuItem::linkToCrud('Order source', 'fas fa-shopping-cart', OrderSource::class);
        yield MenuItem::linkToCrud('Order Types', 'fas fa-tags', OrderType::class);
        yield MenuItem::linkToCrud('Order', 'fas fa-shopping-bag', Order::class);
        yield MenuItem::linkToCrud('Order Items', 'fas fa-box', OrderItems::class)->setController(OrderItemsCrudController::class);
        yield MenuItem::linkToCrud('Cart', 'fas fa-boxes', Cart::class);
        yield MenuItem::linkToCrud('Carrier', 'fas fa-truck', Carrier::class);
        

        yield MenuItem::section('Payment');
        yield MenuItem::linkToCrud('Payments', 'fas fa-credit-card', Payments::class);
        yield MenuItem::linkToCrud('Payment Types', 'fas fa-credit-card', PaymentType::class);
        yield MenuItem::linkToCrud('Statuts de Paiement', 'fas fa-credit-card', StatusPayment::class);
        yield MenuItem::linkToCrud('Méthodes de Paiement', 'fas fa-credit-card', PaymentMethod::class);
        yield MenuItem::linkToCrud('Configuration Stripe', 'fab fa-stripe', StripeConfig::class);
         

        yield MenuItem::section('Taxe');
        yield MenuItem::linkToCrud('Taxes', 'fas fa-percent', Tax::class);
        yield MenuItem::linkToCrud('Order Taxes', 'fas fa-receipt', OrderTax::class);

        yield MenuItem::section('Product');
        yield MenuItem::linkToCrud('Product Variants', 'fas fa-boxes', ProductVariant::class)->setController(ProductVariantCrudController::class);
        yield MenuItem::linkToCrud('Product', 'fas fa-shopping-cart', Product::class);
        yield MenuItem::linkToCrud('Colors', 'fas fa-palette', Color::class);
        yield MenuItem::linkToCrud('Styles', 'fas fa-brush', Style::class);
        yield MenuItem::linkToCrud('Sizes', 'fas fa-ruler', Size::class);
        yield MenuItem::linkToCrud('Categories', 'fas fa-list', Categories::class);
        yield MenuItem::linkToRoute('Gestion des Codes-Barres', 'fa fa-barcode', 'admin_barcode_management');
        yield MenuItem::linkToCrud('Types d\'options', 'fas fa-tag', ProductOption::class);
        yield MenuItem::linkToCrud('Valeurs d\'options', 'fas fa-palette', ProductOptionValue::class)->setController(ProductOptionValueCrudController::class);
        yield MenuItem::linkToCrud('Types de Produit', 'fas fa-box', ProductType::class);
        yield MenuItem::linkToCrud('Unités de Vente', 'fas fa-balance-scale', SaleUnit::class)
            ->setController(SaleUnitCrudController::class);

        

        yield MenuItem::section('Location / Réservation');

        yield MenuItem::linkToCrud('Planning & Réservations', 'fas fa-calendar-check', Booking::class)
            ->setController(BookingCrudController::class);

        yield MenuItem::linkToCrud('Règles & Stocks', 'fas fa-sliders-h', BookingConfiguration::class)
            ->setController(BookingConfigurationCrudController::class);

        yield MenuItem::linkToCrud('Grilles Tarifaires (Packs)', 'fas fa-tags', RentalPack::class)
            ->setController(RentalPackCrudController::class);

        yield MenuItem::linkToCrud('Véhicules Gemsuite', 'fas fa-car', Vehicle::class)
            ->setController(VehicleCrudController::class);

              
    }
}
