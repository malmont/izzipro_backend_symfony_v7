<?php

namespace App\MemoiresVivantes\UseCase\Team;

use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TeamRoleService\TeamRoleService;

/** PUT /api/memoires/admin/users/{id}/team : pose ou retire le rôle de biographe, journalisé ; idempotent */
final class SetBiographerUseCase
{
    public function __construct(private readonly TeamRoleService $teamRoles)
    {
    }

    /** @return array{id: int, isBiographer: bool, teamRoles: list<string>, roles: list<string>, changed: bool} */
    public function execute(User $user, bool $biographer): array
    {
        $changed = $this->teamRoles->set($user, TeamRoles::MEMOIRES_BIOGRAPHE, $biographer);

        return [
            'id' => (int) $user->getId(),
            'isBiographer' => in_array(TeamRoles::MEMOIRES_BIOGRAPHE, $user->getRoles(), true),
            'teamRoles' => TeamRoles::of($user->getRoles()),
            'roles' => $user->getRoles(),
            'changed' => $changed,
        ];
    }
}
