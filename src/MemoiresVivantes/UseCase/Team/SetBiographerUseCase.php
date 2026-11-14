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

    /**
     * @param ?bool $biographer null : ce rôle n'est pas touché
     * @param ?bool $responsable null : ce rôle n'est pas touché (responsable des biographes, 14/11/2026)
     *
     * @return array{id: int, isBiographer: bool, isResponsable: bool, teamRoles: list<string>, roles: list<string>, changed: bool}
     */
    public function execute(User $user, ?bool $biographer, ?bool $responsable = null): array
    {
        $changed = false;
        if ($biographer !== null) {
            $changed = $this->teamRoles->set($user, TeamRoles::MEMOIRES_BIOGRAPHE, $biographer) || $changed;
        }
        if ($responsable !== null) {
            $changed = $this->teamRoles->set($user, TeamRoles::MEMOIRES_RESPONSABLE, $responsable) || $changed;
        }

        return [
            'id' => (int) $user->getId(),
            'isBiographer' => in_array(TeamRoles::MEMOIRES_BIOGRAPHE, $user->getRoles(), true),
            'isResponsable' => in_array(TeamRoles::MEMOIRES_RESPONSABLE, $user->getRoles(), true),
            'teamRoles' => TeamRoles::of($user->getRoles()),
            'roles' => $user->getRoles(),
            'changed' => $changed,
        ];
    }
}
