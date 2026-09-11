<?php

namespace App\Controller\EntrepriseController;

use App\UseCase\EntrepriseUsecase\CreateEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\GetEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class EntrepriseController extends AbstractController
{
    private CreateEntrepriseUseCase $createEntrepriseUseCase;
    private GetEntrepriseUseCase $getEntrepriseUseCase;
    private UpdateEntrepriseUseCase $updateEntrepriseUseCase;
    private TenantCacheService $cache;

    public function __construct(
        CreateEntrepriseUseCase $createEntrepriseUseCase,
        GetEntrepriseUseCase $getEntrepriseUseCase,
        UpdateEntrepriseUseCase $updateEntrepriseUseCase,
        TenantCacheService $cache
    ) {
        $this->createEntrepriseUseCase = $createEntrepriseUseCase;
        $this->getEntrepriseUseCase = $getEntrepriseUseCase;
        $this->updateEntrepriseUseCase = $updateEntrepriseUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/entreprise', name: 'api_entreprise_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $entrepriseDto = $this->createEntrepriseUseCase->execute($data);
        return $this->json($entrepriseDto);
    }

    #[Route('/api/entreprise/{id}', name: 'api_entreprise_get', methods: ['GET'])]
    public function getEntreprise(int $id, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $locale = $request->get('locale', 'fr');
        $cacheKey = "entreprise_{$id}_{$locale}";
        $tags = ['entreprise', 'entreprise_' . $id];
        $entrepriseDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $host, $locale) {
                error_log("Cache miss for entreprise_{$id} in locale: {$locale}");
                return $this->getEntrepriseUseCase->execute($id, $host, $locale);
            },
            3600,
            $tags
        );
        if (!$entrepriseDto) {
            return $this->json(['error' => 'Entreprise not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($entrepriseDto);
    }

    #[Route('/api/entreprise/{id}', name: 'api_entreprise_update', methods: ['PUT'])]
    public function updateEntreprise(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $host = $request->getSchemeAndHttpHost();
        $locale = $request->get('locale', 'fr');

        $entrepriseDto = $this->updateEntrepriseUseCase->execute($id, $data, $host, $locale);
        if (!$entrepriseDto) {
            return $this->json(['error' => 'Entreprise not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Invalidation du cache de l'entreprise
        $this->cache->delete("entreprise_{$id}_{$locale}");
        $this->cache->invalidateTags(['entreprise', 'entreprise_' . $id]);

        return $this->json($entrepriseDto);
    }
}