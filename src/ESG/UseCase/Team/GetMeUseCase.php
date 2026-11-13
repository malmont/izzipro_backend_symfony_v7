<?php

namespace App\ESG\UseCase\Team;

use App\Entity\User;
use App\ESG\DTO\Output\CompanyOutputDTO;
use App\ESG\Entity\EsgUser;
use App\ESG\Security\Voter\BoussoleTeamVoter;
use App\Security\TeamRoles;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * GET /api/boussole/me : qui est connecté, vu de la Boussole (lot BOUSSOLE-CONSEILLER). `kind` : `company` (compte d'entreprise de la
 * Boussole), `team` (conseiller, administrateur du site ou super administrateur, compte de la base commune) ou `none` (un autre compte
 * du site, un client de la boutique par exemple : aucun droit dans la Boussole).
 */
class GetMeUseCase
{
    public const KIND_COMPANY = 'company';
    public const KIND_TEAM = 'team';
    public const KIND_NONE = 'none';

    /** @return array<string, mixed> */
    public function execute(UserInterface $user): array
    {
        if ($user instanceof EsgUser) {
            return [
                'kind' => self::KIND_COMPANY, 'id' => $user->getId(), 'email' => $user->getEmail(), 'firstName' => $user->getFirstName(), 'lastName' => $user->getLastName(),
                'roles' => $user->getRoles(), 'teamRoles' => [], 'isAdvisor' => false,
                'company' => $user->getCompany() !== null ? new CompanyOutputDTO($user->getCompany()) : null,
            ];
        }
        $roles = $user->getRoles();

        return [
            'kind' => BoussoleTeamVoter::isTeam($user) ? self::KIND_TEAM : self::KIND_NONE,
            'id' => $user instanceof User ? $user->getId() : null,
            'email' => $user instanceof User ? $user->getEmail() : null,
            'firstName' => $user instanceof User ? $user->getFirstname() : null,
            'lastName' => $user instanceof User ? $user->getLastname() : null,
            'roles' => $roles, 'teamRoles' => TeamRoles::of($roles), 'isAdvisor' => in_array(TeamRoles::BOUSSOLE_CONSEILLER, $roles, true),
            'company' => null,
        ];
    }
}
