<?php

namespace App\ESG\Service;

use App\ESG\Entity\EsgCompany;
use App\ESG\Security\Voter\BoussoleTeamVoter;
use App\Services\ContentAuditService\ContentAuditRecorder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Journal des consultations de l'équipe de la Boussole (lot BOUSSOLE-CONSEILLER, 13/11/2026 ; Loi 25) : chaque lecture, par un compte
 * d'équipe, du détail d'une entreprise cliente, d'un de ses diagnostics, d'un de ses rapports ou d'un de ses documents est inscrite
 * dans `content_audit_log` (ressource `boussole_company`, identifiant = l'entreprise, action `view`, qui et quand par le journal,
 * `after` = ce qui a été lu). Les listes ne sont pas journalisées. Une entreprise qui lit ses propres données n'écrit rien.
 */
class TeamViewJournal
{
    public const RESOURCE = 'boussole_company';
    public const ACTION = 'view';
    public const COMPANY = 'company';
    public const SESSION = 'session';
    public const REPORT = 'report';
    public const DOCUMENT = 'document';

    public function __construct(private readonly ContentAuditRecorder $audit, private readonly Security $security)
    {
    }

    /** @param array<string, mixed> $context ce qui a été lu (uuid du diagnostic, identifiant et nom du document) */
    public function viewed(?EsgCompany $company, string $what, array $context = []): void
    {
        if ($company === null || !BoussoleTeamVoter::isTeam($this->security->getUser())) {
            return;
        }
        $this->audit->record(self::RESOURCE, $company->getId(), self::ACTION, null, ['what' => $what, 'company' => $company->getName()] + $context, [$what]);
    }
}
