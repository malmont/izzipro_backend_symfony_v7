<?php

namespace App\ESG\Repository;

use App\ESG\Entity\DiagnosticAnswer;
use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Entity\DiagnosticSession;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<DiagnosticAnswer>
 */
class DiagnosticAnswerRepository extends EntityRepository
{
    /**
     * @return DiagnosticAnswer[]
     */
    public function findBySession(DiagnosticSession $session): array
    {
        return $this->findBy(['session' => $session]);
    }

    /**
     * @return DiagnosticAnswer[]
     */
    public function findBySessionWithQuestion(DiagnosticSession $session): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.question', 'q')
            ->addSelect('q')
            ->where('a.session = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getResult();
    }

    public function findBySessionAndQuestion(DiagnosticSession $session, DiagnosticQuestion $question): ?DiagnosticAnswer
    {
        return $this->findOneBy([
            'session' => $session,
            'question' => $question,
        ]);
    }

    public function countBySession(DiagnosticSession $session): int
    {
        return $this->count(['session' => $session]);
    }

    public function save(DiagnosticAnswer $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DiagnosticAnswer $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
