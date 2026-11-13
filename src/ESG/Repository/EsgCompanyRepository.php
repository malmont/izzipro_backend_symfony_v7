<?php

namespace App\ESG\Repository;

use App\ESG\Entity\EsgCompany;
use App\ESG\Enum\TerritoryEnum;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<EsgCompany>
 */
class EsgCompanyRepository extends EntityRepository
{
    public function findBySlug(string $slug): ?EsgCompany
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Entreprises du site pour l'équipe de la Boussole (lot BOUSSOLE-CONSEILLER) : recherche sans casse sur le nom, la ville et le
     * courriel de contact ; par nom. Rend la page et le total.
     *
     * @return array{total: int, companies: list<EsgCompany>}
     */
    public function searchForTeam(string $q, int $limit, int $offset): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = " WHERE LOWER(name) LIKE :q OR LOWER(COALESCE(city, '')) LIKE :q OR LOWER(contact_email) LIKE :q";
            $params['q'] = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
        }
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM esg_company' . $where, $params);
        $ids = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM esg_company' . $where . ' ORDER BY LOWER(name), id LIMIT ' . $limit . ' OFFSET ' . $offset, $params));
        $byId = [];
        foreach ($ids === [] ? [] : $this->findBy(['id' => $ids]) as $company) {
            $byId[$company->getId()] = $company;
        }

        return ['total' => $total, 'companies' => array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)))];
    }

    /**
     * Compteurs et dernier diagnostic des entreprises d'une page, en quatre requêtes quel que soit le nombre d'entreprises.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{usersCount: int, sessionsCount: int, documentsCount: int, lastSession: ?array<string, mixed>}>
     */
    public function teamStats(array $ids): array
    {
        $stats = array_fill_keys($ids, ['usersCount' => 0, 'sessionsCount' => 0, 'documentsCount' => 0, 'lastSession' => null]);
        if ($ids === []) {
            return $stats;
        }
        $conn = $this->getEntityManager()->getConnection();
        $in = implode(', ', array_map('intval', $ids));
        foreach (['usersCount' => 'esg_user', 'sessionsCount' => 'esg_diag_session', 'documentsCount' => 'esg_document'] as $key => $table) {
            foreach ($conn->fetchAllKeyValue("SELECT company_id, COUNT(*) FROM $table WHERE company_id IN ($in) GROUP BY company_id") as $companyId => $count) {
                $stats[(int) $companyId][$key] = (int) $count;
            }
        }
        $rows = $conn->fetchAllAssociative("SELECT DISTINCT ON (company_id) company_id, uuid, status, score_global, maturity_level, completed_at FROM esg_diag_session WHERE company_id IN ($in) ORDER BY company_id, created_at DESC, id DESC");
        foreach ($rows as $row) {
            $stats[(int) $row['company_id']]['lastSession'] = [
                'uuid' => (string) $row['uuid'],
                'status' => (string) $row['status'],
                'scoreGlobal' => $row['score_global'] !== null ? (float) $row['score_global'] : null,
                'maturityLevel' => $row['maturity_level'],
                'completedAt' => $row['completed_at'] !== null ? (new \DateTimeImmutable((string) $row['completed_at']))->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return $stats;
    }

    /**
     * @return EsgCompany[]
     */
    public function findActiveByTerritory(TerritoryEnum $territory): array
    {
        return $this->findBy([
            'territory' => $territory,
            'isActive' => true,
        ]);
    }

    public function save(EsgCompany $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EsgCompany $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
