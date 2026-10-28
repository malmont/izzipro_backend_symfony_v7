<?php

namespace App\Controller\TeamController;

use App\UseCase\TeamUseCase\CreateTeamUseCase;
use App\UseCase\TeamUseCase\GetTeamUseCase;
use App\UseCase\TeamUseCase\GetTeamListUseCase;
use App\UseCase\TeamUseCase\UpdateTeamUseCase;
use App\UseCase\TeamUseCase\DeleteTeamUseCase;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TeamController extends AbstractController
{
    public function __construct(
        private CreateTeamUseCase $createTeamUseCase,
        private GetTeamUseCase $getTeamUseCase,
        private GetTeamListUseCase $getTeamListUseCase,
        private UpdateTeamUseCase $updateTeamUseCase,
        private DeleteTeamUseCase $deleteTeamUseCase,
        private TenantCacheService $cache,
        private ?MediaUrlResolver $mediaUrlResolver = null
    ) {
    }

    private function resolveHost(Request $request): string
    {
        return $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
    }

    #[Route('/api/team', name: 'api_team_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $host = $this->resolveHost($request);
        $locale = $request->get('locale', 'fr');

        $teamDto = $this->createTeamUseCase->execute($data, $host, $locale);

        return $this->json($teamDto, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/team', name: 'api_team_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $host = $this->resolveHost($request);
        $locale = CacheLocale::normalize($request->get('locale'));

        $cacheKey = "teams_list_{$locale}";

        $teams = $this->cache->get(
            $cacheKey,
            fn () => $this->getTeamListUseCase->execute($host, $locale),
            3600,
            CacheTags::listTags(CacheTags::TEAM)
        );

        return $this->json($teams);
    }

    #[Route('/api/team/{id}', name: 'api_team_get', methods: ['GET'])]
    public function getTeam(int $id, Request $request): JsonResponse
    {
        $host = $this->resolveHost($request);
        $locale = CacheLocale::normalize($request->get('locale'));

        $cacheKey = "team_{$id}_{$locale}";

        $teamDto = $this->cache->get(
            $cacheKey,
            fn () => $this->getTeamUseCase->execute($id, $host, $locale),
            3600,
            CacheTags::itemTags(CacheTags::TEAM, $id)
        );

        return $this->json($teamDto);
    }

    #[Route('/api/team/{id}', name: 'api_team_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $host = $this->resolveHost($request);
        $locale = $request->get('locale', 'fr');

        $teamDto = $this->updateTeamUseCase->execute($id, $data, $host, $locale);

        return $this->json($teamDto);
    }

    #[Route('/api/team/{id}', name: 'api_team_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $locale = $request->get('locale', 'fr');
        $this->deleteTeamUseCase->execute($id, $locale);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
