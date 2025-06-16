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
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

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
        TenantCacheService $cache
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
        $cacheKey = 'fournisseurs_all';

        $fournisseursArray = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($host) {
                $item->expiresAfter(3600);
                $item->tag(['fournisseurs_all']);
                $fournisseurs = $this->getAllFournisseursUseCase->execute();
                return array_map(function ($fournisseur) use ($host) {
                    $dto = new FournisseurOutputDTO($fournisseur, $host);
                    return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
                }, $fournisseur = $fournisseurs);
            },
            /* ttl */ 3600,
            /* extraTags */ ['fournisseurs_all']
        );

        return new JsonResponse($fournisseursArray, JsonResponse::HTTP_OK);
    }

    #[Route('/api/fournisseurs', name: 'create_fournisseur', methods: ['POST'])]
    public function createFournisseur(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fournisseurInputDTO = new FournisseurInputDTO($data);
        $fournisseur = $this->createFournisseurUseCase->execute($fournisseurInputDTO);
        // Invalidation gérée ailleurs si nécessaire
        return new JsonResponse(
            ['success' => 'Fournisseur créé avec succès', 'fournisseur_id' => $fournisseur->getId()],
            JsonResponse::HTTP_CREATED
        );
    }

    #[Route('/api/fournisseurs/{id}', name: 'delete_fournisseur', methods: ['DELETE'])]
    public function deleteFournisseur(Fournisseur $fournisseur): JsonResponse
    {
        $this->deleteFournisseurUseCase->execute($fournisseur);
        // Invalidation gérée ailleurs si nécessaire
        return new JsonResponse(['success' => 'Fournisseur supprimé avec succès'], JsonResponse::HTTP_NO_CONTENT);
    }
}
