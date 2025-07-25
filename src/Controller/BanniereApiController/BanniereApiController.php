<?php

namespace App\Controller\BanniereApiController;

use App\Dto\BanniereInputDto;
use App\Dto\BanniereOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\BanniereUseCase\GetAllBannieresUseCase;
use App\UseCase\BanniereUseCase\CreateBanniereUseCase;
use App\UseCase\BanniereUseCase\UpdateBanniereUseCase;
use App\UseCase\BanniereUseCase\DeleteBanniereUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/bannieres')]
class BanniereApiController extends AbstractController
{
    public function __construct(
        private GetAllBannieresUseCase $getAllBannieresUseCase,
        private CreateBanniereUseCase $createBanniereUseCase,
        private UpdateBanniereUseCase $updateBanniereUseCase,
        private DeleteBanniereUseCase $deleteBanniereUseCase,
        private TenantCacheService $cache
    ) {
    }

    #[Route('', name: 'api_banniere_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $cacheKey = 'bannieres_all';
        $cacheTags = ['bannieres'];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/bannieres';

        $bannieresDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($baseImageUrl) {
                $bannieres = $this->getAllBannieresUseCase->execute();
                // On transforme les entités en DTOs de sortie pour une réponse propre
                return array_map(
                    fn($banniere) => new BanniereOutputDto($banniere, $baseImageUrl),
                    $bannieres
                );
            },
            3600, // Durée de vie du cache en secondes (1 heure)
            $cacheTags
        );

        return $this->json($bannieresDto);
    }

    #[Route('', name: 'api_banniere_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] BanniereInputDto $dto, Request $request): JsonResponse
    {
        $banniere = $this->createBanniereUseCase->execute($dto);
        
        // On retourne un DTO de sortie pour la cohérence
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/bannieres';
        $outputDto = new BanniereOutputDto($banniere, $baseImageUrl);

        return $this->json($outputDto, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_banniere_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] BanniereInputDto $dto, Request $request): JsonResponse
    {
        $banniere = $this->updateBanniereUseCase->execute($id, $dto);

        // On retourne un DTO de sortie pour la cohérence
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/bannieres';
        $outputDto = new BanniereOutputDto($banniere, $baseImageUrl);

        return $this->json($outputDto, Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'api_banniere_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteBanniereUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
