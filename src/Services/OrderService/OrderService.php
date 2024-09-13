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
        // If days are provided, filter orders based on the date
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

        // If no days parameter, return all orders for the given order source
        return $this->orderRepository->findBy(['orderSource' => $orderSourceId]);
    }
}
