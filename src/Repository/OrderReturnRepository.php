<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderReturn;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<OrderReturn> */
class OrderReturnRepository extends EntityRepository
{
    /** @return OrderReturn[] retours d'une commande, les plus anciens d'abord */
    public function findForOrder(Order $order): array
    {
        return $this->findBy(['order' => $order], ['id' => 'ASC']);
    }

    /** @return array<int, int> quantités déjà retournées par ligne vendue : [orderItemId => quantité] */
    public function returnedQuantities(Order $order): array
    {
        $out = [];
        foreach ($this->findForOrder($order) as $return) {
            foreach ($return->getLines() as $line) {
                $out[(int) $line['orderItemId']] = ($out[(int) $line['orderItemId']] ?? 0) + (int) $line['quantity'];
            }
        }

        return $out;
    }
}
