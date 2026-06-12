<?php

namespace App\UseCase\TeamUseCase;

use App\Services\TeamService\TeamService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteTeamUseCase
{
    public function __construct(private TeamService $teamService)
    {
    }

    public function execute(int $id, string $locale = 'fr'): void
    {
        $team = $this->teamService->findByIdAndLocale($id, $locale);
        if (!$team) {
            throw new NotFoundHttpException('Team member not found for ID: ' . $id);
        }

        $this->teamService->deleteTeam($team);
    }
}
