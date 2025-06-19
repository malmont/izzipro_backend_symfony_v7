<?php

namespace App\Controller\Account;

use App\Entity\Order;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AccountController extends AbstractController
{
    // MODIFICATION 1 : On injecte notre provider dans le constructeur
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    #[Route('/account', name: 'app_account')]
    public function index(): Response // <-- On retire l'injection de l'argument
    {
        // MODIFICATION 2 : On récupère l'EM et le repository du tenant
        $em = $this->emProvider->getEntityManager();
        $repoOrder = $em->getRepository(Order::class);

        // La logique est la même, mais sur le bon repository
        $orders = $repoOrder->findBy(['userId' => $this->getUser()], ['id' => 'DESC']);
        
        return $this->render('account/index.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/account/order/{id}', name: 'account_order_details')]
    public function show(int $id): Response // <-- MODIFICATION 3 : On reçoit l'ID, pas l'objet Order
    {
        // On récupère l'EM et le repository du tenant
        $em = $this->emProvider->getEntityManager();
        $order = $em->getRepository(Order::class)->find($id);

        // La logique de vérification est la même, mais avec une commande trouvée dans la bonne BDD
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