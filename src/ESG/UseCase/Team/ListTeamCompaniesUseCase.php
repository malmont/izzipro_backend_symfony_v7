<?php

namespace App\ESG\UseCase\Team;

use App\ESG\DTO\Output\TeamCompanyOutputDTO;
use App\ESG\Entity\EsgCompany;
use App\ESG\Repository\EsgCompanyRepository;
use App\Services\TenantEntityManagerProvider;

/** GET /api/boussole/team/companies : toutes les entreprises du site, sans assignation, paginées (lot BOUSSOLE-CONSEILLER) */
class ListTeamCompaniesUseCase
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{total: int, page: int, limit: int, companies: list<TeamCompanyOutputDTO>} */
    public function execute(string $q, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = min(self::MAX_LIMIT, max(1, $limit));
        /** @var EsgCompanyRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(EsgCompany::class);
        $found = $repo->searchForTeam(trim($q), $limit, ($page - 1) * $limit);
        $stats = $repo->teamStats(array_map(fn (EsgCompany $c) => (int) $c->getId(), $found['companies']));

        return [
            'total' => $found['total'],
            'page' => $page,
            'limit' => $limit,
            'companies' => array_map(fn (EsgCompany $c) => new TeamCompanyOutputDTO($c, $stats[(int) $c->getId()]), $found['companies']),
        ];
    }
}
