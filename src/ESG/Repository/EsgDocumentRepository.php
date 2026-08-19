<?php

namespace App\ESG\Repository;

use App\ESG\Entity\EsgCompany;
use App\ESG\Entity\EsgDocument;
use App\ESG\Enum\DomainEnum;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<EsgDocument>
 */
class EsgDocumentRepository extends EntityRepository
{
    /**
     * @return EsgDocument[]
     */
    public function findByCompany(EsgCompany $company): array
    {
        return $this->findBy(['company' => $company]);
    }

    public function findByCompanyAndCode(EsgCompany $company, string $code): ?EsgDocument
    {
        return $this->findOneBy(['company' => $company, 'code' => $code]);
    }

    /**
     * @return EsgDocument[]
     */
    public function findByCompanyAndDomain(EsgCompany $company, DomainEnum $domain): array
    {
        return $this->findBy(['company' => $company, 'domain' => $domain]);
    }

    public function deleteByCompanyAndCode(EsgCompany $company, string $code): void
    {
        $doc = $this->findByCompanyAndCode($company, $code);
        if ($doc) {
            $this->getEntityManager()->remove($doc);
            $this->getEntityManager()->flush();
        }
    }

    public function save(EsgDocument $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
