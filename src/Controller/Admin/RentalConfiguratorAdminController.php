<?php

namespace App\Controller\Admin;

use App\Services\Booking\RentalConfiguratorService;
use App\Services\Booking\RentalSummaryService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Questionnaire « Configurer une location pas à pas » (`/admin/location/configurer?product=`, 08/11/2026) : questions en langage
 * courant qui remplissent les règles de réservation et la grille tarifaire d'un produit louable, puis « ce que verra le client ».
 * Administrateurs du site ; habillage EasyAdmin ; CSRF sur l'enregistrement.
 */
#[IsGranted('ROLE_ADMIN')]
class RentalConfiguratorAdminController extends AbstractController
{
    private const CSRF = 'rental_configurator';

    public function __construct(private readonly RentalConfiguratorService $configurator, private readonly RentalSummaryService $summary, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/location/configurer', name: 'admin_location_configurer', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $productId = (int) $request->query->get('product', 0);
        $product = $productId > 0 ? $this->configurator->product($productId) : null;
        if ($productId > 0 && $product === null) {
            $this->addFlash('danger', 'Produit introuvable ou sans le mode « location » : cochez « Location » sur sa fiche d\'abord.');

            return $this->redirect($this->pageUrl());
        }

        return $this->page($product, $product ? $this->configurator->answers($product) : null, []);
    }

    #[Route('/admin/location/configurer/{productId}', name: 'admin_location_configurer_save', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function save(int $productId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : réessayez.');

            return $this->redirect($this->pageUrl($productId));
        }
        $product = $this->configurator->product($productId);
        if ($product === null) {
            $this->addFlash('danger', 'Produit introuvable ou sans le mode « location ».');

            return $this->redirect($this->pageUrl());
        }
        $post = $request->request->all();
        $result = $this->configurator->apply($product, $post, $this->getUser()?->getUserIdentifier());
        if ($result['errors'] !== []) {
            $this->addFlash('danger', sprintf('%d réponse(s) à corriger : rien n\'a été enregistré.', count($result['errors'])));

            return $this->page($product, array_intersect_key($post, array_flip(array_keys($this->configurator->answers($product)))) + $this->configurator->answers($product), $result['errors']);
        }
        $this->addFlash('success', 'Location configurée : voici ce que verra le client.' . ($result['summary']['warnings'] !== [] ? ' Des points restent à corriger, voir ci-dessous.' : ''));

        return $this->redirect($this->pageUrl((int) $product->getId()));
    }

    private function page(?\App\Entity\Product $product, ?array $answers, array $errors): Response
    {
        $config = $product?->getBookingConfiguration();
        $products = $this->configurator->products();
        foreach ($products as $i => $p) {
            $products[$i]['url'] = $this->pageUrl($p['id']);
        }

        return $this->render('admin/location/configurer.html.twig', [
            'products' => $products, 'product' => $product, 'answers' => $answers, 'errors' => $errors,
            'current' => $config !== null ? $this->summary->describe($config, $product) : null,
            'csrf_id' => self::CSRF, 'locationUrl' => $this->routeUrl('admin_location'),
            'rulesUrl' => $config !== null ? $this->urls->unsetAll()->setController(BookingConfigurationCrudController::class)->setAction(Action::EDIT)->setEntityId($config->getId())->generateUrl() : null,
            'productUrl' => $product !== null ? $this->urls->unsetAll()->setController(ProductCrudController::class)->setAction(Action::EDIT)->setEntityId($product->getId())->generateUrl() : null,
        ]);
    }

    private function pageUrl(?int $productId = null): string
    {
        return $this->routeUrl('admin_location_configurer', $productId !== null ? ['product' => $productId] : []);
    }

    private function routeUrl(string $route, array $params = []): string
    {
        try {
            return $this->urls->unsetAll()->setRoute($route, $params)->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl($route, $params);
        }
    }
}
