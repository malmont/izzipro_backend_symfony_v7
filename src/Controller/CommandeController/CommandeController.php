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
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

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
        TenantCacheService $cache,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null // devise du site (lot G1, 09/11/2026)
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
            function () use ($collection, $host) {
                $commandes = $this->getCommandesByCollectionUseCase->execute($collection);
                $commandesArray = $commandes->toArray();
                return array_map(function ($commande) use ($host) {
                    $dto = new CommandeOutputDTO($commande, $host, $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT);
                    return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
                }, $commandesArray);
            },
            600,
            [...CacheTags::itemTags(CacheTags::COLLECTIONS, $collection->getId()), ...CacheTags::listTags(CacheTags::COMMANDES)]
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

        $input = new \App\Services\GestionService\GestionInput(is_array($data) ? $data : []);
        $data = ['budget' => $input->cents('budget'), 'name' => $input->text('name'), 'date' => $input->date('date')?->format('Y-m-d H:i:s')];
        $fournisseurId = $input->id('fournisseur');
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)
        if ($collection->getIsClosed()) {
            throw new \App\Services\GestionService\GestionConflictException('COLLECTION_CLOSED', 'La collection est figée : aucune commande ne peut y être ajoutée.'); // 409 (lot G4, 09/11/2026)
        }
        $fournisseurDTO = new FournisseurInputDTO(['id' => $fournisseurId]);
        $commande = $this->createCommandeUseCase->execute($data, $collection, $fournisseurDTO);
        // 201 avec l'objet créé (lot G4, 09/11/2026 ; avant : {"success"})
        return $this->json(new CommandeOutputDTO($commande, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT), JsonResponse::HTTP_CREATED);
    }
}
