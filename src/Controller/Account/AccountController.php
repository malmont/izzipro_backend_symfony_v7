<?php

namespace App\Controller\Account;

use App\Entity\Order;
use App\Services\TenantEntityManagerProvider; 
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AccountController extends AbstractController
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    #[Route('/account', name: 'app_account')]
    public function index(): Response
    {
        $em = $this->emProvider->getEntityManager();
        $repoOrder = $em->getRepository(Order::class);
        $orders = $repoOrder->findBy(['userId' => $this->getUser()], ['id' => 'DESC']);
        
        return $this->render('account/index.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/account/order/{id}', name: 'account_order_details')]
    public function show(int $id): Response 
    {
        $em = $this->emProvider->getEntityManager();
        $order = $em->getRepository(Order::class)->find($id);
        if (!$order || $order->getUserOrder() != $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        if ($order->isIspaid() == false) {
            return $this->redirectToRoute('app_account');
        }
        
        return $this->render('account/details_order.html.twig', [
            'order' => $order,
        ]);
    }
}