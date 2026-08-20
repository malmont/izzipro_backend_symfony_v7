<?php

namespace App\UseCase\TeamUseCase;

use App\Dto\TeamInputDto;
use App\Dto\TeamOutputDto;
use App\Services\TeamService\TeamService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateTeamUseCase
{
    public function __construct(private TeamService $teamService)
    {
    }

    public function execute(int $id, array $data, string $host, string $locale = 'fr'): TeamOutputDto
    {
        $team = $this->teamService->findByIdAndLocale($id, $locale);
        if (!$team) {
            throw new NotFoundHttpException('Team member not found for ID: ' . $id);
        }

        $dto = new TeamInputDto();
        $dto->name = array_key_exists('name', $data) ? $data['name'] : null;
        $dto->role = array_key_exists('role', $data) ? $data['role'] : null;
        $dto->description = array_key_exists('description', $data) ? $data['description'] : null;
        $dto->image = array_key_exists('image', $data) ? $data['image'] : null;

        $updatedTeam = $this->teamService->updateTeam($team, $dto);

        return TeamOutputDto::fromEntity($updatedTeam, $host, $locale);
    }
}
