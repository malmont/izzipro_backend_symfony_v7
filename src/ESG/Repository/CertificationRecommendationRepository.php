<?php

namespace App\ESG\Repository;

use App\ESG\Entity\CertificationRecommendation;
use App\ESG\Entity\DiagnosticSession;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<CertificationRecommendation>
 */
class CertificationRecommendationRepository extends EntityRepository
{
    /**
     * @return CertificationRecommendation[]
     */
    public function findBySessionOrdered(DiagnosticSession $session): array
    {
        return $this->findBy(
            ['session' => $session],
            ['priority' => 'ASC']
        );
    }

    public function deleteBySession(DiagnosticSession $session): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.session = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->execute();
    }

    public function save(CertificationRecommendation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CertificationRecommendation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
