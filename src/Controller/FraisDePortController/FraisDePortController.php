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
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class FraisDePortController extends AbstractController
{
    private GetFraisDePortByCommandeUseCase $getFraisDePortByCommandeUseCase;
    private CreateFraisDePortUseCase $createFraisDePortUseCase;
    private DeleteFraisDePortUseCase $deleteFraisDePortUseCase;
    private TenantCacheService $cache;

    public function __construct(
        GetFraisDePortByCommandeUseCase $getFraisDePortByCommandeUseCase,
        CreateFraisDePortUseCase $createFraisDePortUseCase,
        DeleteFraisDePortUseCase $deleteFraisDePortUseCase,
        TenantCacheService $cache
    ) {
        $this->getFraisDePortByCommandeUseCase = $getFraisDePortByCommandeUseCase;
        $this->createFraisDePortUseCase = $createFraisDePortUseCase;
        $this->deleteFraisDePortUseCase = $deleteFraisDePortUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'create_frais_de_port', methods: ['POST'])]
    public function createFraisDePort(Commande $commande, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $inputDTO = new FraisDePortInputDTO(
            $data['name'] ?? '',
            $data['facture'] ?? '',
            $data['tracknumber'] ?? '',
            (float)($data['price'] ?? 0),
            (int)($data['transporteur']['id'] ?? 0)
        );

        $this->createFraisDePortUseCase->execute($commande, $inputDTO);
        // Invalidation du cache gérée par listener/event subscriber si configuré
        return $this->json(['success' => 'Frais de port created'], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/commandes/{id}/frais-de-port', name: 'get_frais_de_port', methods: ['GET'])]
    public function getFraisDePort(Commande $commande, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'frais_de_port_commande_' . $commande->getId();

        $fraisDePort = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($commande, $host) {
                $item->expiresAfter(3600);
                $item->tag(['frais_de_port']);
                return $this->getFraisDePortByCommandeUseCase->execute($commande, $host);
            },
            /* ttl */ 3600,
            /* extraTags */ ['frais_de_port']
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
        // Invalidation du cache gérée par listener/event subscriber si configuré
        return $this->json(['success' => 'Frais de port deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
