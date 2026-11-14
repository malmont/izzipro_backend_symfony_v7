<?php

namespace App\Dto;

use App\Entity\UserAppMembership;

/** Une appartenance telle que l'API la rend (`GET /api/account/memberships`, `docs/apps-membres.md`) */
final class UserAppMembershipDto
{
    public function __construct(
        public readonly string $app,
        public readonly string $joinedAt,
        public readonly string $source,
        public readonly ?string $termsVersion,
        public readonly ?string $termsAcceptedAt,
    ) {
    }

    public static function fromEntity(UserAppMembership $m): self
    {
        return new self($m->getApp(), $m->getJoinedAt()->format(\DateTimeInterface::ATOM), $m->getSource(), $m->getTermsVersion(), $m->getTermsAcceptedAt()?->format(\DateTimeInterface::ATOM));
    }
}
