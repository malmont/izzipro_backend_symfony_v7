<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Security\AppFamilies;
use App\Services\AppMembershipService\AppTermsService;

/** GET /api/apps/{app}/terms : conditions de la famille d'une application, avec leur version (`docs/apps-membres.md` § 3) */
class GetAppTermsUseCase
{
    public function __construct(private readonly AppTermsService $terms)
    {
    }

    /** @return array<string, mixed>|null null : ni un code d'application à membres, ni une famille */
    public function execute(string $app, string $host, string $locale): ?array
    {
        $family = AppFamilies::of($app);

        return $family === null ? null : $this->terms->terms($family, $host, $locale);
    }
}
