<?php
namespace App\Services\OrderService;

use App\Entity\Order; // <-- On importe l'entité
use App\Repository\OrderRepository; // <-- On importe le repository pour le type-hint
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use DateTime;

class OrderService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * MODIFICATION 2 : On crée une méthode privée pour récupérer le repository du tenant.
     */
    private function getOrderRepository(): OrderRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(Order::class);
    }

    public function getOrdersByOrderSource(int $orderSourceId, ?int $days = null)
    {
        // MODIFICATION 3 : On utilise notre nouvelle méthode privée
        $orderRepository = $this->getOrderRepository();

        if ($days) {
            $date = new DateTime();
            $date->modify("-$days days");

            return $orderRepository->createQueryBuilder('o')
                ->where('o.orderSource = :orderSourceId')
                ->andWhere('o.orderDate >= :date')
                ->setParameter('orderSourceId', $orderSourceId)
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }
        return $orderRepository->findBy(['orderSource' => $orderSourceId]);
    }

    public function getOrdersByUser(int $userId)
    {
        $orderRepository = $this->getOrderRepository();
        return $orderRepository->findBy(['userId' => $userId]);
    }
}