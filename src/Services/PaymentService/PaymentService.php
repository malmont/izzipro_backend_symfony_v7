<?php

namespace App\Services\PaymentService;

use App\Entity\Payments;
use App\Services\TenantEntityManagerProvider;
use DateTime;

class PaymentService
{
    /**
     * MODIFIÉ : La seule dépendance est maintenant le provider.
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getPaymentsByOrderSource(int $orderSourceId, ?int $days = null)
    {
        /**
         * MODIFIÉ : On récupère l'EM et le repository ici, une seule fois.
         */
        $em = $this->emProvider->getEntityManager();
        $paymentsRepository = $em->getRepository(Payments::class);

        /**
         * INCHANGÉ : Votre logique if/else est conservée.
         * La seule différence est qu'on utilise la variable locale $paymentsRepository
         * au lieu de la propriété $this->paymentsRepository.
         */
        if ($days) {
            $date = new DateTime();
            $date->modify("-$days days");

            return $paymentsRepository->createQueryBuilder('p')
                ->leftJoin('p.orderPayment', 'o')
                ->where('o.orderSource = :orderSourceId')
                ->andWhere('p.paymentDate >= :date')
                ->setParameter('orderSourceId', $orderSourceId)
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }

        return $paymentsRepository->createQueryBuilder('p')
            ->leftJoin('p.orderPayment', 'o')
            ->where('o.orderSource = :orderSourceId')
            ->setParameter('orderSourceId', $orderSourceId)
            ->getQuery()
            ->getResult();
    }
    /** Lot CMD-WEB-1 : une page des paiements (`null` : toutes les sources), même fenêtre de jours que la liste entière */
    public function getPaymentsPageByOrderSource(?int $orderSourceId, ?int $days, int $offset, int $limit): array
    {
        $since = $days ? (new DateTime())->modify("-$days days") : null;

        return $this->emProvider->getEntityManager()->getRepository(Payments::class)->pageBySource($orderSourceId, $since, $offset, $limit);
    }
}