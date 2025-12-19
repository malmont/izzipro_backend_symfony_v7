<?php

namespace App\Controller\BookingController;

use App\Entity\Product;
use App\Form\TenantAccessTokenType;
use App\Form\BookingSetupType;
use App\Dto\BookingSetupRequest;
use App\Services\Booking\BookingAuthService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\UpdateBookingConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/booking-setup')]
class BookingSetupController extends AbstractController
{
    public function __construct(
        private BookingAuthService $authService,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider
    ) {}

    #[Route('/', name: 'app_booking_auth', methods: ['GET', 'POST'])]
    public function auth(Request $request): Response
    {
        $subdomain = $this->tenantManager->getCurrentTenantCode();
        if (!$subdomain) {
            throw $this->createAccessDeniedException('Sous-domaine requis.');
        }

        if ($this->authService->isAuthenticated()) {
            return $this->redirectToRoute('app_booking_list');
        }

        $form = $this->createForm(TenantAccessTokenType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $token = $form->get('token')->getData();
            
            if ($this->authService->attemptLogin($token)) {
                $this->addFlash('success', 'Connexion réussie.');
                return $this->redirectToRoute('app_booking_list');
            } else {
                $this->addFlash('danger', 'Token invalide.');
            }
        }

        return $this->render('booking_setup/auth.html.twig', [
            'form' => $form->createView(),
            'shopName' => ucfirst($subdomain)
        ]);
    }

    #[Route('/list', name: 'app_booking_list')]
    public function list(): Response
    {
        if (!$this->authService->isAuthenticated()) {
            return $this->redirectToRoute('app_booking_auth');
        }

        $em = $this->emProvider->getEntityManager();
        
        $products = $em->getRepository(Product::class)->findAll();

        return $this->render('booking_setup/list.html.twig', [
            'products' => $products
        ]);
    }

    #[Route('/configure/{id}', name: 'app_booking_configure')]
    public function configure(
        Product $product, 
        Request $request, 
        UpdateBookingConfiguration $useCase
    ): Response
    {
        if (!$this->authService->isAuthenticated()) {
            return $this->redirectToRoute('app_booking_auth');
        }

        $dto = BookingSetupRequest::createFromProduct($product);

        $form = $this->createForm(BookingSetupType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $useCase->execute($product, $dto);

            $this->addFlash('success', 'Configuration mise à jour !');
            return $this->redirectToRoute('app_booking_list');
        }

        return $this->render('booking_setup/configure.html.twig', [
            'product' => $product,
            'form' => $form->createView()
        ]);
    }

    #[Route('/logout', name: 'app_booking_logout')]
    public function logout(): Response
    {
        $this->authService->logout();
        $this->addFlash('info', 'Déconnexion effectuée.');
        return $this->redirectToRoute('app_booking_auth');
    }
}