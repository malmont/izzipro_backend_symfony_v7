<?php

namespace App\Repository;

use App\Entity\FormSubmission;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/** @extends EntityRepository<FormSubmission> */
class FormSubmissionRepository extends EntityRepository
{
    /**
     * @param array{formId?: ?string, status?: ?string, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable} $filters
     * @return array{0: list<FormSubmission>, 1: int} demandes de la page (plus récentes d'abord) et total
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $qb = $this->filtered($filters);
        $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)
            ->getQuery()->getResult();

        return [$items, $total];
    }

    /** @return list<FormSubmission> pour l'export, plus récentes d'abord, $limit au plus */
    public function forExport(array $filters, int $limit): array
    {
        return $this->filtered($filters)->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countFiltered(array $filters): int
    {
        return (int) $this->filtered($filters)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return list<array{formId: string, formName: string, count: int, lastAt: string}> formulaires qui ont reçu des demandes */
    public function forms(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT form_id, (ARRAY_AGG(form_name ORDER BY created_at DESC))[1] AS form_name, COUNT(*) AS n, MAX(created_at) AS last_at FROM form_submission GROUP BY form_id ORDER BY MAX(created_at) DESC'
        );

        return array_map(fn (array $r) => ['formId' => (string) $r['form_id'], 'formName' => (string) $r['form_name'], 'count' => (int) $r['n'], 'lastAt' => (new \DateTimeImmutable((string) $r['last_at']))->format(DATE_ATOM)], $rows);
    }

    public function referenceExists(string $reference): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM form_submission WHERE reference = ?', [$reference]);
    }

    /** Supprime les demandes reçues avant $before ; renvoie le nombre supprimé */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM form_submission WHERE created_at < ?', [$before->format('Y-m-d H:i:s')]);
    }

    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s');
        if (($filters['formId'] ?? null) !== null) {
            $qb->andWhere('s.formId = :formId')->setParameter('formId', $filters['formId']);
        }
        if (($filters['status'] ?? null) !== null) {
            $qb->andWhere('s.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['from'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('s.createdAt >= :from')->setParameter('from', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('s.createdAt < :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }
}
