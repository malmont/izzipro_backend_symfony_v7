<?php

namespace App\UseCase\TeamUseCase;

use App\Dto\TeamOutputDto;
use App\Services\TeamService\TeamService;

class GetTeamListUseCase
{
    public function __construct(private TeamService $teamService)
    {
    }

    /**
     * @return TeamOutputDto[]
     */
    public function execute(string $host, string $locale = 'fr'): array
    {
        $teams = $this->teamService->findAllByLocale($locale);

        return array_map(
            fn($team) => TeamOutputDto::fromEntity($team, $host, $locale),
            $teams
        );
    }
}
