<?php

namespace App\Services\OrderService;

use App\Repository\OrderRepository;
use DateTime;

class OrderService
{
    private $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public function getOrdersByOrderSource(int $orderSourceId, ?int $days = null)
    {
        if ($days) {
            $date = new DateTime();
            $date->modify("-$days days");

            return $this->orderRepository->createQueryBuilder('o')
                ->where('o.orderSource = :orderSourceId')
                ->andWhere('o.orderDate >= :date')
                ->setParameter('orderSourceId', $orderSourceId)
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }
        return $this->orderRepository->findBy(['orderSource' => $orderSourceId]);
    }

    public function getOrdersByUser(int $userId)
    {
        return $this->orderRepository->findBy(['userId' => $userId]);
    }
}
