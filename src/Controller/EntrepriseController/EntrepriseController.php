<?php

namespace App\Controller\EntrepriseController;

use App\UseCase\EntrepriseUsecase\CreateEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\GetEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;

class EntrepriseController extends AbstractController
{
    private CreateEntrepriseUseCase $createEntrepriseUseCase;
    private GetEntrepriseUseCase $getEntrepriseUseCase;
    private UpdateEntrepriseUseCase $updateEntrepriseUseCase;
    private TenantCacheService $cache;
    private ?MediaUrlResolver $mediaUrlResolver;

    public function __construct(
        CreateEntrepriseUseCase $createEntrepriseUseCase,
        GetEntrepriseUseCase $getEntrepriseUseCase,
        UpdateEntrepriseUseCase $updateEntrepriseUseCase,
        TenantCacheService $cache,
        ?MediaUrlResolver $mediaUrlResolver = null
    ) {
        $this->createEntrepriseUseCase = $createEntrepriseUseCase;
        $this->getEntrepriseUseCase = $getEntrepriseUseCase;
        $this->updateEntrepriseUseCase = $updateEntrepriseUseCase;
        $this->cache = $cache;
        $this->mediaUrlResolver = $mediaUrlResolver;
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
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
        $locale = CacheLocale::normalize($request->get('locale'));
        $cacheKey = "entreprise_{$id}_{$locale}";
        // la fiche porte ses enfants (traductions, réseaux sociaux, adresse) : étiquettes de liste et de fiche
        $entrepriseDto = $this->cache->get(
            $cacheKey,
            fn () => $this->getEntrepriseUseCase->execute($id, $host, $locale),
            3600,
            [...CacheTags::listTags(CacheTags::ENTREPRISE), ...CacheTags::itemTags(CacheTags::ENTREPRISE, $id)]
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
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
        $locale = $request->get('locale', 'fr');

        try {
            $entrepriseDto = $this->updateEntrepriseUseCase->execute($id, $data, $host, $locale);
        } catch (\App\Services\EntrepriseService\EntrepriseValidationException $e) {
            return $this->json(['error' => $e->getMessage(), 'errors' => array_slice($e->errors, 0, 50)], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$entrepriseDto) {
            return $this->json(['error' => 'Entreprise not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // le cache de la fiche est vidé par l'écriture Doctrine elle-même (registre CacheTags, 28/10/2026)
        return $this->json($entrepriseDto);
    }
}