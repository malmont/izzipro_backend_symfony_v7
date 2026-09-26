<?php

namespace App\Controller\StripeController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stripe')]
#[IsGranted('ROLE_ADMIN')]
class StripeController extends AbstractController
{
    #[Route('/connect', name: 'app_stripe_connect', methods: ['GET', 'POST'])]
    public function connect(): Response
    {
        return $this->redirectToRoute('admin_stripe_connect');
    }

    #[Route('/success', name: 'app_stripe_success', methods: ['GET'])]
    public function success(): Response
    {
        return $this->redirectToRoute('admin_stripe_success');
    }

    #[Route('/disconnect', name: 'app_stripe_disconnect', methods: ['GET', 'POST'])]
    public function disconnect(Request $request): Response
    {
        // Transmet le jeton CSRF du formulaire : la route admin le vérifie
        $token = (string) ($request->request->get('_csrf_token') ?? $request->request->get('_token') ?? $request->query->get('_token', ''));

        return $this->redirectToRoute('admin_stripe_disconnect', ['_token' => $token]);
    }
}