<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\EsgCompany;

/**
 * Une entreprise dans la liste de l'équipe de la Boussole (GET /api/boussole/team/companies) : la fiche rendue à l'entreprise elle-même
 * (`CompanyOutputDTO`), plus son état, sa date de création, ses compteurs et son dernier diagnostic.
 */
class TeamCompanyOutputDTO extends CompanyOutputDTO
{
    public bool $isActive;
    public ?string $createdAt;
    public int $usersCount;
    public int $sessionsCount;
    public int $documentsCount;
    /** @var array{uuid: string, status: string, scoreGlobal: ?float, maturityLevel: ?string, completedAt: ?string}|null */
    public ?array $lastSession;

    /** @param array{usersCount: int, sessionsCount: int, documentsCount: int, lastSession: ?array<string, mixed>} $stats */
    public function __construct(EsgCompany $company, array $stats)
    {
        parent::__construct($company);
        $this->isActive = $company->isActive();
        $this->createdAt = $company->getCreatedAt()?->format(\DateTimeInterface::ATOM);
        $this->usersCount = $stats['usersCount'];
        $this->sessionsCount = $stats['sessionsCount'];
        $this->documentsCount = $stats['documentsCount'];
        $this->lastSession = $stats['lastSession'];
    }
}
