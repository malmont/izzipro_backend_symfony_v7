<?php

namespace App\Controller\EntrepriseController;

use App\UseCase\EntrepriseUsecase\CreateEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\GetEntrepriseUseCase;
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
    private TenantCacheService $cache;

    public function __construct(
        CreateEntrepriseUseCase $createEntrepriseUseCase,
        GetEntrepriseUseCase $getEntrepriseUseCase,
        TenantCacheService $cache
    ) {
        $this->createEntrepriseUseCase = $createEntrepriseUseCase;
        $this->getEntrepriseUseCase = $getEntrepriseUseCase;
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
        $locale = $request->getLocale();
        $cacheKey = "entreprise_{$id}_{$locale}";

        $entrepriseDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $host, $locale) {
                $item->expiresAfter(3600);
                $item->tag(['entreprise', 'entreprise_' . $id]);
                
                error_log("Cache miss for entreprise_{$id} in locale: {$locale}");
                return $this->getEntrepriseUseCase->execute($id, $host, $locale);
            }
        );

        if (!$entrepriseDto) {
            return $this->json(['error' => 'Entreprise not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($entrepriseDto);
    }
}
