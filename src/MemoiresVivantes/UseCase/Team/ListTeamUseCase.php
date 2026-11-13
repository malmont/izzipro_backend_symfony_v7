<?php

namespace App\MemoiresVivantes\UseCase\Team;

use App\MemoiresVivantes\Dto\TeamMemberOutputDto;
use App\Security\TeamRoles;
use App\Services\TeamRoleService\TeamRoleService;

/** GET /api/memoires/team : les biographes de l'équipe (ROLE_MEMOIRES_BIOGRAPHE), par nom */
final class ListTeamUseCase
{
    public function __construct(private readonly TeamRoleService $teamRoles)
    {
    }

    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        return array_map(fn ($user) => (new TeamMemberOutputDto($user))->toArray(), $this->teamRoles->members(TeamRoles::MEMOIRES_BIOGRAPHE));
    }
}
