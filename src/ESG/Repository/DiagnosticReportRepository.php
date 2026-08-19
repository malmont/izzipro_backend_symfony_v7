<?php

namespace App\ESG\Repository;

use App\ESG\Entity\DiagnosticReport;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Enum\ReportStatusEnum;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<DiagnosticReport>
 */
class DiagnosticReportRepository extends EntityRepository
{
    public function findBySession(DiagnosticSession $session): ?DiagnosticReport
    {
        return $this->findOneBy(['session' => $session]);
    }

    /**
     * @return DiagnosticReport[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => ReportStatusEnum::PENDING], ['requestedAt' => 'ASC']);
    }

    public function save(DiagnosticReport $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DiagnosticReport $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
