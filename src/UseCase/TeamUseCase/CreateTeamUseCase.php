<?php

namespace App\UseCase\TeamUseCase;

use App\Dto\TeamInputDto;
use App\Dto\TeamOutputDto;
use App\Services\TeamService\TeamService;

class CreateTeamUseCase
{
    public function __construct(private TeamService $teamService)
    {
    }

    public function execute(array $data, string $host, string $locale = 'fr'): TeamOutputDto
    {
        $dto = new TeamInputDto();
        $dto->name = $data['name'] ?? null;
        $dto->role = $data['role'] ?? null;
        $dto->description = $data['description'] ?? null;
        $dto->image = $data['image'] ?? null;

        $team = $this->teamService->createTeam($dto);

        return TeamOutputDto::fromEntity($team, $host, $locale);
    }
}
