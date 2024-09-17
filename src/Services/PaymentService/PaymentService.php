<?php
namespace App\Services\PaymentService;

use App\Repository\PaymentsRepository;
use DateTime;

class PaymentService
{
    private $paymentsRepository;

    public function __construct(PaymentsRepository $paymentsRepository)
    {
        $this->paymentsRepository = $paymentsRepository;
    }

    public function getPaymentsByOrderSource(int $orderSourceId, ?int $days = null)
    {
        // Si le paramètre 'days' est fourni, on filtre par la date
        if ($days) {
            $date = new DateTime();
            $date->modify("-$days days");

            return $this->paymentsRepository->createQueryBuilder('p')
                ->leftJoin('p.orderPayment', 'o')
                ->where('o.orderSource = :orderSourceId')
                ->andWhere('p.paymentDate >= :date')
                ->setParameter('orderSourceId', $orderSourceId)
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }

        // Si 'days' n'est pas fourni, on retourne tous les paiements pour la source de commande donnée
        return $this->paymentsRepository->createQueryBuilder('p')
            ->leftJoin('p.orderPayment', 'o')
            ->where('o.orderSource = :orderSourceId')
            ->setParameter('orderSourceId', $orderSourceId)
            ->getQuery()
            ->getResult();
    }
}
