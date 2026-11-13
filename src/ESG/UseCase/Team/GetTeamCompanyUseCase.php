<?php

namespace App\ESG\UseCase\Team;

use App\ESG\DTO\Output\EsgDocumentOutputDTO;
use App\ESG\DTO\Output\SessionOutputDTO;
use App\ESG\DTO\Output\TeamCompanyOutputDTO;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgCompany;
use App\ESG\Entity\EsgDocument;
use App\ESG\Entity\EsgUser;
use App\ESG\Repository\EsgCompanyRepository;
use App\ESG\Service\TeamViewJournal;
use App\Services\TenantEntityManagerProvider;

/**
 * GET /api/boussole/team/companies/{id} : le détail d'une entreprise pour l'équipe (fiche, comptes, diagnostics, documents). La lecture
 * est inscrite au journal des consultations. Rend null si l'entreprise n'existe pas sur ce site.
 */
class GetTeamCompanyUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TeamViewJournal $journal)
    {
    }

    /** @return array{company: TeamCompanyOutputDTO, users: list<array<string, mixed>>, sessions: list<SessionOutputDTO>, documents: list<EsgDocumentOutputDTO>}|null */
    public function execute(int $id): ?array
    {
        $em = $this->emProvider->getEntityManager();
        /** @var EsgCompanyRepository $repo */
        $repo = $em->getRepository(EsgCompany::class);
        $company = $repo->find($id);
        if (!$company instanceof EsgCompany) {
            return null;
        }
        $users = $em->getRepository(EsgUser::class)->findBy(['company' => $company], ['id' => 'ASC']);
        $sessions = $em->getRepository(DiagnosticSession::class)->findByCompanyOrdered($company);
        $documents = $em->getRepository(EsgDocument::class)->findByCompany($company);
        $this->journal->viewed($company, TeamViewJournal::COMPANY);

        return [
            'company' => new TeamCompanyOutputDTO($company, $repo->teamStats([$id])[$id]),
            'users' => array_map(fn (EsgUser $u) => ['id' => $u->getId(), 'email' => $u->getEmail(), 'firstName' => $u->getFirstName(), 'lastName' => $u->getLastName(), 'isActive' => $u->isActive()], $users),
            'sessions' => array_map(fn (DiagnosticSession $s) => new SessionOutputDTO($s), $sessions),
            'documents' => array_map(fn (EsgDocument $d) => new EsgDocumentOutputDTO($d, self::downloadUrl((int) $d->getId())), $documents),
        ];
    }

    public static function downloadUrl(int $documentId): string
    {
        return '/api/boussole/team/documents/' . $documentId . '/download';
    }
}
