<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Dto\UserAppMembershipDto;
use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppMembershipService;

/** GET /api/account/memberships : familles dont le compte connecté est membre (`docs/apps-membres.md` § 5) */
class ListMyMembershipsUseCase
{
    public function __construct(private readonly AppMembershipService $memberships)
    {
    }

    /** @return array{team: bool, memberships: list<UserAppMembershipDto>} */
    public function execute(User $user): array
    {
        return ['team' => AppFamilies::isTeam($user), 'memberships' => array_map(UserAppMembershipDto::fromEntity(...), $this->memberships->of($user))];
    }
}
