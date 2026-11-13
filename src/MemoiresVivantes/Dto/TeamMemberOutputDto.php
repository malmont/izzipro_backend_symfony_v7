<?php

namespace App\MemoiresVivantes\Dto;

use App\Entity\User;
use App\Security\TeamRoles;

/** GET /api/memoires/team : un membre de l'équipe (liste légère pour le choix du biographe d'un rendez-vous) */
final class TeamMemberOutputDto
{
    public function __construct(private readonly User $user)
    {
    }

    /** @return array{id: int, fullName: string, email: ?string, teamRoles: list<string>} */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->user->getId(),
            'fullName' => trim(($this->user->getFirstname() ?? '') . ' ' . ($this->user->getLastname() ?? '')),
            'email' => $this->user->getEmail(),
            'teamRoles' => TeamRoles::of($this->user->getRoles()),
        ];
    }
}
