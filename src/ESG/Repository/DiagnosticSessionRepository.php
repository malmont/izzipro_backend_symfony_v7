<?php

namespace App\ESG\Repository;

use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgCompany;
use App\ESG\Enum\SessionStatusEnum;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @extends EntityRepository<DiagnosticSession>
 */
class DiagnosticSessionRepository extends EntityRepository
{
    public function findByUuid(string $uuid): ?DiagnosticSession
    {
        try {
            $uuidObj = Uuid::fromString($uuid);
            return $this->findOneBy(['uuid' => $uuidObj]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return DiagnosticSession[]
     */
    public function findByCompanyOrdered(EsgCompany $company): array
    {
        return $this->findBy(
            ['company' => $company],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * @return DiagnosticSession[]
     */
    public function findCompletedByCompanyOrderedByCompletedAt(EsgCompany $company): array
    {
        return $this->findBy(
            [
                'company' => $company,
                'status' => SessionStatusEnum::COMPLETED,
            ],
            ['completedAt' => 'ASC']
        );
    }

    public function findInProgressByCompany(EsgCompany $company): ?DiagnosticSession
    {
        return $this->findOneBy([
            'company' => $company,
            'status' => SessionStatusEnum::IN_PROGRESS,
        ]);
    }

    public function save(DiagnosticSession $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DiagnosticSession $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
