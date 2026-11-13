<?php

namespace App\ESG\UseCase\Team;

use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TeamRoleService\TeamRoleService;

/** GET /api/boussole/team/members : les conseillers de la Boussole (ROLE_BOUSSOLE_CONSEILLER), par nom ; forme de la liste d'équipe de Mémoires Vivantes */
class ListTeamMembersUseCase
{
    public function __construct(private readonly TeamRoleService $teamRoles)
    {
    }

    /** @return list<array{id: int, fullName: string, email: ?string, teamRoles: list<string>}> */
    public function execute(): array
    {
        return array_map(fn (User $user) => [
            'id' => (int) $user->getId(),
            'fullName' => trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? '')),
            'email' => $user->getEmail(),
            'teamRoles' => TeamRoles::of($user->getRoles()),
        ], $this->teamRoles->members(TeamRoles::BOUSSOLE_CONSEILLER));
    }
}
