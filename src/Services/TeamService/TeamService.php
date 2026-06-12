<?php

namespace App\Services\TeamService;

use App\Dto\TeamInputDto;
use App\Entity\Team;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;

class TeamService
{
    private $repository;

    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private TranslationGeneratorService $translationGenerator
    ) {
        $tenantEm = $this->emProvider->getEntityManager();
        $this->repository = $tenantEm->getRepository(Team::class);
    }

    public function findAllByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }

    public function findByIdAndLocale(int $id, string $locale): ?Team
    {
        return $this->repository->findByIdAndLocale($id, $locale);
    }

    public function createTeam(TeamInputDto $dto): Team
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $team = new Team();
        $team->setName($dto->name);
        $team->setRole($dto->role);
        $team->setDescription($dto->description);
        $team->setImage($dto->image);
        $team->setGemsuiteTeamId($dto->gemsuiteTeamId);

        $tenantEm->persist($team);
        
        // Generate translations automatically using the translation service
        $this->translationGenerator->generateTranslations($team);

        $tenantEm->flush();

        return $team;
    }

    public function updateTeam(Team $team, TeamInputDto $dto): Team
    {
        $tenantEm = $this->emProvider->getEntityManager();

        if ($dto->name !== null) {
            $team->setName($dto->name);
        }
        if ($dto->role !== null) {
            $team->setRole($dto->role);
        }
        if ($dto->description !== null) {
            $team->setDescription($dto->description);
        }
        if ($dto->image !== null) {
            $team->setImage($dto->image);
        }
        if ($dto->gemsuiteTeamId !== null) {
            $team->setGemsuiteTeamId($dto->gemsuiteTeamId);
        }

        // Re-generate translations to account for changes
        $this->translationGenerator->generateTranslations($team);

        $tenantEm->flush();

        return $team;
    }

    public function deleteTeam(Team $team): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($team);
        $tenantEm->flush();
    }
}
