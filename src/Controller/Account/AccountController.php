<?php

namespace App\Controller\Account;

use App\UseCase\OrderUseCase\GetAccountOrderDetailUseCase;
use App\UseCase\OrderUseCase\GetAccountOrdersUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AccountController extends AbstractController
{
    public function __construct(
        private readonly GetAccountOrdersUseCase $getAccountOrdersUseCase,
        private readonly GetAccountOrderDetailUseCase $getAccountOrderDetailUseCase
    ) {}

    #[Route('/account', name: 'app_account')]
    public function index(): Response
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        $orders = $user ? $this->getAccountOrdersUseCase->execute($user->getId()) : [];

        return $this->render('account/index.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/account/order/{id}', name: 'account_order_details')]
    public function show(int $id): Response 
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_home');
        }

        $order = $this->getAccountOrderDetailUseCase->execute($id, $user->getId());
        if (!$order) {
            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/details_order.html.twig', [
            'order' => $order,
        ]);
    }
}