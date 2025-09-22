<?php
namespace App\Repository;

use App\Entity\Color;
use Doctrine\ORM\EntityRepository; 

class ColorRepository extends EntityRepository
{
    /**
     * @param string $locale
     * @return Color[]
     */
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.translations', 't', 'WITH', 't.language = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}