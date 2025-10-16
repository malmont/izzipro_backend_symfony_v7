<?php
// src/Controller/StripeController.php

namespace App\Controller\StripeController; 
use App\Form\StripeConnectionTokenType;
use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/stripe')]
class StripeController extends AbstractController
{
    #[Route('/connect', name: 'app_stripe_connect', methods: ['GET', 'POST'])]
    public function connect(
        Request $request, 
        StripeService $stripeService, 
        UrlGeneratorInterface $urlGenerator,
        TenantConnectionManager $tenantConnectionManager
    ): Response
    {
        $subdomainCode = $tenantConnectionManager->getCurrentTenantCode();
        
        if ($subdomainCode === null) {
            $this->addFlash('danger', 'L\'accès à cette page doit se faire via un sous-domaine valide.');
            return $this->redirectToRoute('app_tenant_setup'); 
        }

        $stripeConfig = $stripeService->getStripeConfigForCurrentTenant();

        if ($stripeConfig) {
            return $this->render('stripe/status.html.twig', [
                'stripe_config' => $stripeConfig,
            ]);
        }

        $form = $this->createForm(StripeConnectionTokenType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $tokenSubmitted = $form->get('token')->getData();
            $expectedToken = $tenantConnectionManager->getTenantToken($subdomainCode);

            if ($expectedToken === null) {
                $this->addFlash('danger', 'Ce site n\'est pas reconnu ou n\'a pas de token de sécurité configuré.');
                return $this->render('stripe/connect.html.twig', ['form' => $form->createView()]);
            }

            if ($tokenSubmitted !== $expectedToken) {
                $this->addFlash('danger', 'Le token de sécurité est invalide. Veuillez vérifier et réessayer.');
                return $this->render('stripe/connect.html.twig', ['form' => $form->createView()]);
            }

            $returnUrl = $urlGenerator->generate('app_stripe_success', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $refreshUrl = $urlGenerator->generate('app_stripe_connect', [], UrlGeneratorInterface::ABSOLUTE_URL);
            
            $onboardingLink = $stripeService->createOnboardingLink($refreshUrl, $returnUrl);
            
            return $this->redirect($onboardingLink);
        }

        return $this->render('stripe/connect.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    
    #[Route('/success', name: 'app_stripe_success')]
    public function success(StripeService $stripeService): Response
    {
        $success = $stripeService->finalizeConnection();

        if ($success) {
            $this->addFlash('success', 'Vos clients peuvent maintenant acheter sur votre plateforme e-commerce.');
        } else {
            $this->addFlash('danger', 'Une erreur est survenue lors de la finalisation de la connexion à Stripe. Veuillez réessayer.');
        }
        return $this->render('stripe/success.html.twig', [
            'connection_success' => $success,
        ]);
    }

    #[Route('/disconnect', name: 'app_stripe_disconnect', methods: ['POST'])]
    public function disconnect(StripeService $stripeService): Response
    {
        $stripeService->disconnectCurrentTenant();
        $this->addFlash('info', 'Votre ancien compte Stripe a été déconnecté. Vous pouvez maintenant en connecter un nouveau.');

        return $this->redirectToRoute('app_stripe_connect');
    }
}