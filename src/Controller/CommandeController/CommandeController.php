<?php

namespace App\Controller\CommandeController;

use App\Entity\Collections;
use App\UseCase\CommandeUseCase\GetCommandesByCollectionUseCase;
use App\UseCase\CommandeUseCase\CreateCommandeUseCase;
use App\Dto\CommandeOutputDTO;
use App\Dto\FournisseurInputDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class CommandeController extends AbstractController
{
    private GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase;
    private CreateCommandeUseCase $createCommandeUseCase;
    private Security $security;
    private TenantCacheService $cache;

    public function __construct(
        GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase,
        CreateCommandeUseCase $createCommandeUseCase,
        Security $security,
        TenantCacheService $cache
    ) {
        $this->getCommandesByCollectionUseCase = $getCommandesByCollectionUseCase;
        $this->createCommandeUseCase = $createCommandeUseCase;
        $this->security = $security;
        $this->cache = $cache;
    }

    #[Route('/api/collections/{id}/commandes', name: 'get_commandes_by_collection', methods: ['GET'])]
    public function getCommandesByCollection(Collections $collection, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'commandes_collection_' . $collection->getId();
        $commandesDTO = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($collection, $host) {
                $item->expiresAfter(600); 
                $item->tag(['commandes_by_collection']);
                $commandes = $this->getCommandesByCollectionUseCase->execute($collection);
                $commandesArray = $commandes->toArray();
                return array_map(function ($commande) use ($host) {
                    $dto = new CommandeOutputDTO($commande, $host);
                    return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
                }, $commandesArray);
            },
        );

        return $this->json($commandesDTO, JsonResponse::HTTP_OK);
    }

    #[Route('/api/collections/{id}/commandes', name: 'create_commande', methods: ['POST'])]
    public function createCommande(Request $request, Collections $collection): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $currentUser = $this->security->getUser();

        if (!$currentUser) {
            return $this->json(['error' => 'Unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $fournisseurDTO = new FournisseurInputDTO($data['fournisseur'] ?? []);
        $this->createCommandeUseCase->execute($data, $collection, $fournisseurDTO);

        return $this->json(['success' => 'Commande created'], JsonResponse::HTTP_CREATED);
    }
}
