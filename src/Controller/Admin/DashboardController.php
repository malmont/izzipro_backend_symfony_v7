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
use App\Entity\InventoryMovements;
use App\Entity\Payments;
use App\Entity\StatusCommande;
use App\Entity\Tax;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\PaymentType;
use App\Controller\Admin\OrderAllCrudController;
use App\Controller\Admin\OrderCrudController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig');
        // $routeBuilder = $this->get(AdminUrlGenerator::class);
        // return $this->redirect($routeBuilder->setController(OrderCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Ecommerce'); 
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        
        
        
        yield MenuItem::section('UI');
        yield MenuItem::linkToCrud('Home Slider', 'fas fa-images', HomeSlider::class);
        
        yield MenuItem::section('EASYMAKEMONEY');
        yield MenuItem::linkToCrud('Collections', 'fas fa-archive', Collections::class); // Ajoutez cette ligne
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-cart', Commande::class);
        yield MenuItem::linkToCrud('Notes de Frais', 'fas fa-receipt', NoteDeFrais::class);
        yield MenuItem::linkToCrud('Fournisseurs', 'fas fa-truck', Fournisseur::class);
        yield MenuItem::linkToCrud('Transporteurs', 'fas fa-truck', Transporteur::class);
        yield MenuItem::linkToCrud('Frais de Port', 'fas fa-shipping-fast', FraisDePort::class);
        
        yield MenuItem::section('Mouvements User');
        yield MenuItem::linkToCrud('Adresse user', 'fas fa-shipping-fast', Adress::class);
        yield MenuItem::linkToCrud('Contact', 'fas fa-envelope', Contact::class);
        yield MenuItem::linkToCrud('Users', 'fas fa-user', User::class);

        yield MenuItem::section('Mouvements stock');
        yield MenuItem::linkToCrud('Types de Mouvement', 'fas fa-exchange-alt', MovementType::class);
        yield MenuItem::linkToCrud('Inventory Movements', 'fas fa-dolly', InventoryMovements::class);

        yield MenuItem::section('Caisse');
        yield MenuItem::linkToCrud('Caisse', 'fas fa-cash-register', Caisse::class);
        yield MenuItem::linkToCrud('Transaction Caisse', 'fas fa-cash-register', TransactionCaisse::class)->setController(TransactionCaisseCrudController::class);
        yield MenuItem::linkToCrud('Transaction Type caisse', 'fas fa-exchange-alt', TransactionType::class);

        yield MenuItem::section('Order');
        yield MenuItem::linkToCrud('complet order', 'fas fa-list', Order::class)
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


    }
}
