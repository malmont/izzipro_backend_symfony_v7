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
        ?\App\Services\MediaUrlResolver $mediaUrlResolver = null
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
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $input = new \App\Services\GestionService\GestionInput($data);
        $inputDTO = new FraisDePortInputDTO(
            (string) $input->text('name'),
            (string) $input->text('facture', false),
            (string) $input->text('tracknumber', false),
            (int) $input->cents('price'),
            (int) $input->id('transporteur')
        );
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)

        $this->createFraisDePortUseCase->execute($commande, $inputDTO);
        return $this->json(['success' => 'Frais de port created'], JsonResponse::HTTP_CREATED);
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
        if (!$fraisDePort) {
            return $this->json(
                ['error' => 'No shipping cost associated with this order'],
                JsonResponse::HTTP_NOT_FOUND
            );
        }
        return $this->json($fraisDePort, JsonResponse::HTTP_OK);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'delete_frais_de_port', methods: ['DELETE'])]
    public function deleteFraisDePort(Commande $commande): JsonResponse
    {
        $this->deleteFraisDePortUseCase->execute($commande);
        return $this->json(['success' => 'Frais de port deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
