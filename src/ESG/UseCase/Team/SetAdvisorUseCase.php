<?php

namespace App\ESG\UseCase\Team;

use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TeamRoleService\TeamRoleService;

/**
 * PUT /api/boussole/team/members/{userId} : pose ou retire le rôle de conseiller de la Boussole sur un compte du site ; idempotent ;
 * journalisé par le service commun des rôles d'équipe (content_audit_log, user_team, grant | revoke).
 */
class SetAdvisorUseCase
{
    public function __construct(private readonly TeamRoleService $teamRoles)
    {
    }

    /** @return array{id: int, isAdvisor: bool, teamRoles: list<string>, roles: list<string>, changed: bool} */
    public function execute(User $user, bool $member): array
    {
        $changed = $this->teamRoles->set($user, TeamRoles::BOUSSOLE_CONSEILLER, $member);

        return [
            'id' => (int) $user->getId(),
            'isAdvisor' => in_array(TeamRoles::BOUSSOLE_CONSEILLER, $user->getRoles(), true),
            'teamRoles' => TeamRoles::of($user->getRoles()),
            'roles' => $user->getRoles(),
            'changed' => $changed,
        ];
    }
}
