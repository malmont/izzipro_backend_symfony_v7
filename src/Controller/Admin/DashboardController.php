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
        yield MenuItem::linkToCrud('Product', 'fas fa-shopping-cart', Product::class);
        yield MenuItem::linkToCrud('Order', 'fas fa-shopping-bag', Order::class);
        yield MenuItem::linkToCrud('Cart', 'fas fa-boxes', Cart::class);
        yield MenuItem::linkToCrud('Categories', 'fas fa-list', Categories::class);
        yield MenuItem::linkToCrud('Carrier', 'fas fa-truck', Carrier::class);
        yield MenuItem::linkToCrud('Home Slider', 'fas fa-images', HomeSlider::class);
        yield MenuItem::linkToCrud('Contact', 'fas fa-envelope', Contact::class);
        yield MenuItem::linkToCrud('Users', 'fas fa-user', User::class);
        yield MenuItem::linkToCrud('Collections', 'fas fa-archive', Collections::class); // Ajoutez cette ligne
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-cart', Commande::class);
        yield MenuItem::linkToCrud('Colors', 'fas fa-palette', Color::class);
        yield MenuItem::linkToCrud('Styles', 'fas fa-brush', Style::class);
        yield MenuItem::linkToCrud('Sizes', 'fas fa-ruler', Size::class);
        yield MenuItem::linkToCrud('Product Variants', 'fas fa-boxes', ProductVariant::class);
    }
}
