<?php

namespace App\Controller\Admin;

use App\Services\StripeService\StripeService;
use App\Services\TenantConnectionManager;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/stripe')]
#[IsGranted('ROLE_ADMIN')]
class AdminStripeController extends AbstractController
{
    /**
     * Lance la procédure privée de création/connexion du compte Stripe Connect depuis EasyAdmin.
     */
    #[Route('/connect', name: 'admin_stripe_connect', methods: ['GET', 'POST'])]
    public function connect(
        StripeService $stripeService,
        UrlGeneratorInterface $urlGenerator,
        AdminUrlGenerator $adminUrlGenerator,
        TenantConnectionManager $tenantConnectionManager
    ): Response {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? $_SERVER['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: null;
        if (empty($secretKey)) {
            $this->addFlash('danger', 'La clé secrète Stripe (STRIPE_SECRET_KEY) n\'est pas encore configurée dans le fichier .env du serveur.');
            return $this->redirect($adminUrlGenerator->setController(StripeConfigCrudController::class)->generateUrl());
        }

        $subdomainCode = $tenantConnectionManager->getCurrentTenantCode();
        if ($subdomainCode === null) {
            $this->addFlash('danger', 'Impossible d\'identifier le tenant courant.');
            return $this->redirect($adminUrlGenerator->setController(StripeConfigCrudController::class)->generateUrl());
        }

        try {
            $returnUrl = $urlGenerator->generate('admin_stripe_success', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $refreshUrl = $urlGenerator->generate('admin_stripe_connect', [], UrlGeneratorInterface::ABSOLUTE_URL);

            $onboardingLink = $stripeService->createOnboardingLink($refreshUrl, $returnUrl);

            return $this->redirect($onboardingLink);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erreur Stripe lors de la génération du lien d\'onboarding : ' . $e->getMessage());
            return $this->redirect($adminUrlGenerator->setController(StripeConfigCrudController::class)->generateUrl());
        }
    }

    /**
     * URL de retour appelée par Stripe une fois l'onboarding terminé.
     */
    #[Route('/success', name: 'admin_stripe_success', methods: ['GET'])]
    public function success(
        StripeService $stripeService,
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        try {
            $success = $stripeService->finalizeConnection();

            if ($success) {
                $this->addFlash('success', '🎉 Félicitations ! Votre compte Stripe a été connecté avec succès. Les paiements par carte bancaire sont désormais opérationnels.');
            } else {
                $this->addFlash('warning', 'La procédure Stripe a été enregistrée, mais des vérifications ou informations complémentaires sont requises par Stripe avant d\'activer les paiements.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erreur lors de la finalisation de la connexion Stripe : ' . $e->getMessage());
        }

        return $this->redirect($adminUrlGenerator->setController(StripeConfigCrudController::class)->generateUrl());
    }

    /**
     * Déconnecte le compte Stripe associé au tenant courant.
     */
    #[Route('/disconnect', name: 'admin_stripe_disconnect', methods: ['GET', 'POST'])]
    public function disconnect(
        StripeService $stripeService,
        AdminUrlGenerator $adminUrlGenerator
    ): RedirectResponse {
        $disconnected = $stripeService->disconnectCurrentTenant();

        if ($disconnected) {
            $this->addFlash('info', 'Le compte Stripe a été déconnecté avec succès pour ce tenant. Vous pouvez en connecter un nouveau à tout moment.');
        } else {
            $this->addFlash('warning', 'Aucun compte Stripe actif n\'était associé à ce tenant.');
        }

        return $this->redirect($adminUrlGenerator->setController(StripeConfigCrudController::class)->generateUrl());
    }
}
