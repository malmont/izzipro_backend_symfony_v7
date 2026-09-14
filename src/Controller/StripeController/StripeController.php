<?php

namespace App\Controller\StripeController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function disconnect(): Response
    {
        return $this->redirectToRoute('admin_stripe_disconnect');
    }
}