<?php

namespace App\Controller\FraisDePortController;

use App\Entity\Commande;
use App\Dto\FraisDePortInputDTO;
use App\UseCase\FraisDePortUseCase\GetFraisDePortByCommandeUseCase;
use App\UseCase\FraisDePortUseCase\CreateFraisDePortUseCase;
use App\UseCase\FraisDePortUseCase\DeleteFraisDePortUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class FraisDePortController extends AbstractController
{
    private GetFraisDePortByCommandeUseCase $getFraisDePortByCommandeUseCase;
    private CreateFraisDePortUseCase $createFraisDePortUseCase;
    private DeleteFraisDePortUseCase $deleteFraisDePortUseCase;
    private TenantCacheService $cache;
    private ?\App\Services\MediaUrlResolver $mediaUrlResolver;

    public function __construct(
        GetFraisDePortByCommandeUseCase $getFraisDePortByCommandeUseCase,
        CreateFraisDePortUseCase $createFraisDePortUseCase,
        DeleteFraisDePortUseCase $deleteFraisDePortUseCase,
        TenantCacheService $cache,
        ?\App\Services\MediaUrlResolver $mediaUrlResolver = null,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null, // lot G4 (09/11/2026)
        private readonly ?\App\UseCase\FraisDePortUseCase\GetFraisDePortByCollectionUseCase $getFraisDePortByCollectionUseCase = null, // lot B3 bis (12/11/2026)
        private readonly ?\App\UseCase\FraisDePortUseCase\UpdateFraisDePortUseCase $updateFraisDePortUseCase = null, // lot EDIT (12/11/2026)
    ) {
        $this->getFraisDePortByCommandeUseCase = $getFraisDePortByCommandeUseCase;
        $this->createFraisDePortUseCase = $createFraisDePortUseCase;
        $this->deleteFraisDePortUseCase = $deleteFraisDePortUseCase;
        $this->cache = $cache;
        $this->mediaUrlResolver = $mediaUrlResolver;
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'create_frais_de_port', methods: ['POST'])]
    public function createFraisDePort(Commande $commande, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Corps JSON invalide', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $input = new \App\Gestion\Services\GestionInput($data);
        $inputDTO = new FraisDePortInputDTO(
            (string) $input->text('name'),
            (string) $input->text('facture', false),
            (string) $input->text('tracknumber', false),
            (int) $input->cents('price'),
            (int) $input->id('transporteur')
        );
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)

        $fraisDePort = $this->createFraisDePortUseCase->execute($commande, $inputDTO);
        $host = $this->mediaUrlResolver ? $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost()) : $request->getSchemeAndHttpHost();
        // 201 avec l'objet créé (lot G4, 09/11/2026 ; avant : {"success"})
        return $this->json(new \App\Dto\FraisDePortOutputDTO($fraisDePort, $host, $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'get_frais_de_port', methods: ['GET'])]
    public function getFraisDePort(Commande $commande, Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver 
            ? $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost()) 
            : $request->getSchemeAndHttpHost();
        $cacheKey = 'frais_de_port_commande_' . $commande->getId();

        // frais de port d'une commande fournisseur (enfants de la commande), avec leur transporteur
        $fraisDePort = $this->cache->get(
            $cacheKey,
            fn () => $this->getFraisDePortByCommandeUseCase->execute($commande, $host),
            3600,
            [...CacheTags::itemTags(CacheTags::COMMANDES, $commande->getId()), ...CacheTags::listTags(CacheTags::TRANSPORTEURS)]
        );
        // commande sans frais de port : 200 null (lot G4, 09/11/2026 ; avant : 404)
        return $this->json($fraisDePort ?: null, JsonResponse::HTTP_OK);
    }

    /** Frais de port de toutes les commandes d'une période, en une lecture (lot B3 bis, 12/11/2026) */
    #[Route('/api/collections/{id}/frais-de-port', name: 'get_frais_de_port_by_collection', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getFraisDePortByCollection(\App\Entity\Collections $collection, Request $request): JsonResponse
    {
        $host = $this->mediaUrlResolver ? $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost()) : $request->getSchemeAndHttpHost();

        return $this->json($this->cache->get(
            'frais_de_port_collection_' . $collection->getId(),
            fn () => $this->getFraisDePortByCollectionUseCase->execute($collection, $host),
            600,
            // toute écriture d'une commande ou de son frais de port (enfant de la commande), d'un transporteur ou d'un fournisseur vide la lecture
            [...CacheTags::itemTags(CacheTags::COLLECTIONS, $collection->getId()), ...CacheTags::listTags(CacheTags::COMMANDES), ...CacheTags::listTags(CacheTags::TRANSPORTEURS), ...CacheTags::listTags(CacheTags::FOURNISSEURS)]
        ));
    }

    /** Modification partielle (lot EDIT, 12/11/2026) : 200 avec l'objet de la lecture ; commande figée ou période fermée : 409 */
    #[Route('/api/commandes/{id}/frais-de-port', name: 'patch_frais_de_port', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patchFraisDePort(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $fraisDePort = $this->updateFraisDePortUseCase->execute($id, $data);
        $host = $this->mediaUrlResolver ? $this->mediaUrlResolver->getPublicHost($request->getSchemeAndHttpHost()) : $request->getSchemeAndHttpHost();

        return $this->json(new \App\Dto\FraisDePortOutputDTO($fraisDePort, $host, $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT));
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'delete_frais_de_port', methods: ['DELETE'])]
    public function deleteFraisDePort(Commande $commande): \Symfony\Component\HttpFoundation\Response
    {
        $this->deleteFraisDePortUseCase->execute($commande);
        return new \Symfony\Component\HttpFoundation\Response('', \Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT); // 204 sans corps (lot G4, 09/11/2026)
    }
}
