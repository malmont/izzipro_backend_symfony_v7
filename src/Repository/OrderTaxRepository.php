<?php

namespace App\Repository;

use App\Entity\OrderTax;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<OrderTax>
 */
class OrderTaxRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

    /**
     * INCHANGÉ : Votre méthode personnalisée fonctionnera parfaitement car
     * $this->createQueryBuilder() utilisera l'EntityManager du tenant.
     */
    public function getTotalTaxByMonth(int $year, int $month): float
    {
        $startDate = new \DateTime("$year-$month-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        return (float) $this->createQueryBuilder('ot')
            ->select('SUM(ot.amount)')
            ->join('ot.orderTax', 'o')
            ->where('o.orderDate BETWEEN :start AND :end') // Utilisation de 'orderDate' pour la comparaison
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }
}