<?php

namespace App\Controller\FournisseurController;

use App\Dto\FournisseurInputDTO;
use App\Dto\FournisseurOutputDTO;
use App\UseCase\FournisseurUseCase\CreateFournisseurUseCase;
use App\UseCase\FournisseurUseCase\GetAllFournisseursUseCase;
use App\UseCase\FournisseurUseCase\DeleteFournisseurUseCase;
use App\Entity\Fournisseur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class FournisseurController extends AbstractController
{
    private GetAllFournisseursUseCase $getAllFournisseursUseCase;
    private CreateFournisseurUseCase $createFournisseurUseCase;
    private DeleteFournisseurUseCase $deleteFournisseurUseCase;
    private TenantCacheService $cache;

    public function __construct(
        GetAllFournisseursUseCase $getAllFournisseursUseCase,
        CreateFournisseurUseCase $createFournisseurUseCase,
        DeleteFournisseurUseCase $deleteFournisseurUseCase,
        TenantCacheService $cache,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null // devise du site (lot G6, 10/11/2026)
    ) {
        $this->getAllFournisseursUseCase = $getAllFournisseursUseCase;
        $this->createFournisseurUseCase = $createFournisseurUseCase;
        $this->deleteFournisseurUseCase = $deleteFournisseurUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/fournisseurs', name: 'get_all_fournisseurs', methods: ['GET'])]
    public function getAllFournisseurs(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $query = \App\Gestion\Services\GestionListQuery::fromRequest($request); // ?q=&page=&limit= (lot G5, 10/11/2026)
        $cacheKey = 'fournisseurs_all' . $query->cacheSuffix();
        $result = $this->cache->get(
            $cacheKey,
            function () use ($host, $query) {
                $result = $this->getAllFournisseursUseCase->search($query);
                return ['items' => array_map(fn ($fournisseur) => new FournisseurOutputDTO($fournisseur, $host, $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT), $result['items']), 'total' => $result['total']];
            },
            3600,
            CacheTags::listTags(CacheTags::FOURNISSEURS)
        );
        return new JsonResponse($result['items'], JsonResponse::HTTP_OK, $query->headers($result['total']));
    }

    #[Route('/api/fournisseurs', name: 'create_fournisseur', methods: ['POST'])]
    public function createFournisseur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fournisseurInputDTO = new FournisseurInputDTO($data);
        $fournisseur = $this->createFournisseurUseCase->execute($fournisseurInputDTO);
        // Invalidation gérée ailleurs si nécessaire
        // 201 avec l'objet créé (lot G4, 09/11/2026 ; avant : {"success", "fournisseur_id"})
        return $this->json(new FournisseurOutputDTO($fournisseur, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/fournisseurs/{id}', name: 'delete_fournisseur', methods: ['DELETE'])]
    public function deleteFournisseur(Fournisseur $fournisseur): \Symfony\Component\HttpFoundation\Response
    {
        $this->deleteFournisseurUseCase->execute($fournisseur);
        // Invalidation gérée ailleurs si nécessaire
        return new \Symfony\Component\HttpFoundation\Response('', \Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT); // 204 sans corps (lot G4)
    }
}
