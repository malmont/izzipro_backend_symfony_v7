<?php

namespace App\Controller\BanniereApiController;

use App\Dto\BanniereInputDto;
use App\Dto\BanniereOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\BanniereUseCase\GetAllBannieresUseCase;
use App\UseCase\BanniereUseCase\CreateBanniereUseCase;
use App\UseCase\BanniereUseCase\UpdateBanniereUseCase;
use App\UseCase\BanniereUseCase\DeleteBanniereUseCase;
use App\UseCase\BanniereUseCase\GetBanniereByIdUseCase;
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
        private TenantCacheService $cache,
        private GetBanniereByIdUseCase $getBanniereByIdUseCase
    ) {
    }

    #[Route('', name: 'api_banniere_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'bannieres_all_' . $locale; 
        $cacheTags = ['bannieres'];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                return $this->getAllBannieresUseCase->execute($locale, $baseImageUrl);
            },
            3600, 
            $cacheTags
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_banniere_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'banniere_' . $id . '_' . $locale;
        $cacheTags = ['banniere_' . $id];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dto = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                return $this->getBanniereByIdUseCase->execute($id, $locale, $baseImageUrl);
            },
            3600,
            $cacheTags
        );

        if (!$dto) {
            return $this->json(['message' => 'Bannière non trouvée'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($dto);
    }

    #[Route('', name: 'api_banniere_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] BanniereInputDto $dto, Request $request): JsonResponse
    {
        $banniere = $this->createBanniereUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets//uploads/slider';
        $outputDto = new BanniereOutputDto($banniere, $baseImageUrl);

        return $this->json($outputDto, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_banniere_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] BanniereInputDto $dto, Request $request): JsonResponse
    {
        $banniere = $this->updateBanniereUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets//uploads/slider';
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
