<?php

namespace App\ESG\Repository;

use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Enum\DomainEnum;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<DiagnosticQuestion>
 */
class DiagnosticQuestionRepository extends EntityRepository
{
    /**
     * @return DiagnosticQuestion[]
     */
    public function findActiveByDomain(DomainEnum $domain): array
    {
        return $this->findBy(
            ['domain' => $domain, 'isActive' => true],
            ['displayOrder' => 'ASC']
        );
    }

    /**
     * @return DiagnosticQuestion[]
     */
    public function findAllActiveOrdered(): array
    {
        return $this->findBy(
            ['isActive' => true],
            ['displayOrder' => 'ASC']
        );
    }

    public function save(DiagnosticQuestion $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DiagnosticQuestion $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
