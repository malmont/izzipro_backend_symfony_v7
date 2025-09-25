<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Services\TenantEntityManagerProvider;
use DateTime;

class OrderService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    private function getOrderRepository(): OrderRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(Order::class);
    }

    public function getOrdersByOrderSource(int $orderSourceId, ?int $days = null)
    {
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