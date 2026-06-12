<?php

namespace App\UseCase\TeamUseCase;

use App\Dto\TeamOutputDto;
use App\Services\TeamService\TeamService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetTeamUseCase
{
    public function __construct(private TeamService $teamService)
    {
    }

    public function execute(int $id, string $host, string $locale = 'fr'): TeamOutputDto
    {
        $team = $this->teamService->findByIdAndLocale($id, $locale);
        if (!$team) {
            throw new NotFoundHttpException('Team member not found for ID: ' . $id);
        }

        return TeamOutputDto::fromEntity($team, $host, $locale);
    }
}
