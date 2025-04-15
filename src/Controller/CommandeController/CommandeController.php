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
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class CommandeController extends AbstractController
{
    private GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase;
    private CreateCommandeUseCase $createCommandeUseCase;
    private Security $security;
    private CacheInterface $cache;

    public function __construct(
        GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase,
        CreateCommandeUseCase $createCommandeUseCase,
        Security $security,
        CacheInterface $cache
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
        // Construire une clé de cache basée sur l'ID de la collection
        $cacheKey = 'commandes_collection_' . $collection->getId();

        // Utilisation du cache avec TTL de 5 minutes et ajout d'un tag "commandes_by_collection"
        $commandesDTO = $this->cache->get($cacheKey, function (ItemInterface $item) use ($collection, $host) {
            $item->expiresAfter(600); // Cache expire après 5 minutes
                $item->tag(['commandes_by_collection']);
            // Récupérer les entités Commande via le use case
            $commandes = $this->getCommandesByCollectionUseCase->execute($collection);
            // Convertir la collection en tableau et transformer chaque commande en DTO
            $commandesArray = $commandes->toArray();
            return array_map(function ($commande) use ($host) {
                $dto = new CommandeOutputDTO($commande, $host);
                // Si le DTO possède une méthode toArray(), on peut l'appeler pour obtenir un tableau simple
                return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
            }, $commandesArray);
        });
        return $this->json($commandesDTO, 200);
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
