<?php

namespace App\Controller\Account;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AccountController extends AbstractController
{
    #[Route('/account', name: 'app_account')]
    public function index(OrderRepository $repoOrder): Response
    {
        $orders = $repoOrder->findBy(['userId'=>$this->getUser()],['id'=>'DESC']);
        return $this->render('account/index.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/account/order/{id}', name: 'account_order_details')]
    public function show(?Order $order): Response
    {
        if(!$order || $order->getUserOrder() != $this->getUser()){
            return $this->redirectToRoute('app_home');
        }
        if( $order->isIspaid()==false){
            return $this->redirectToRoute('app_account');
        }
        
        return $this->render('account/details_order.html.twig', [
            'order' => $order,
        ]);
    }
}
