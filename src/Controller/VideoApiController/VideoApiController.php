<?php
namespace App\Controller\VideoApiController;

use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Dto\VideoInputDto;
use App\Dto\VideoOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\VideoUseCase\GetAllVideosUseCase;
use App\UseCase\VideoUseCase\CreateVideoUseCase;
use App\UseCase\VideoUseCase\UpdateVideoUseCase;
use App\UseCase\VideoUseCase\DeleteVideoUseCase;
use App\UseCase\VideoUseCase\GetVideoByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: ['/api/videos', '/videos'])]
class VideoApiController extends AbstractController
{
    public function __construct(
        private GetAllVideosUseCase $getAllVideosUseCase,
        private CreateVideoUseCase $createVideoUseCase,
        private UpdateVideoUseCase $updateVideoUseCase,
        private DeleteVideoUseCase $deleteVideoUseCase,
        private TenantCacheService $cache,
        private GetVideoByIdUseCase $getVideoByIdUseCase,
        private ?\App\Services\MediaUrlResolver $mediaUrlResolver = null
    ) {}

    #[Route('', name: 'api_video_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'videos_all_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver
            ? $this->mediaUrlResolver->getSliderBaseUrl($request->getSchemeAndHttpHost())
            : ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dtos = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllVideosUseCase->execute($baseImageUrl, $locale),
            3600,
            CacheTags::listTags(CacheTags::VIDEOS)
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_video_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'video_' . $id . '_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver
            ? $this->mediaUrlResolver->getSliderBaseUrl($request->getSchemeAndHttpHost())
            : ($request->getSchemeAndHttpHost() . '/assets/uploads/slider');

        $dto = $this->cache->get(
            $cacheKey,
            fn () => $this->getVideoByIdUseCase->execute($id, $baseImageUrl, $locale),
            3600,
            CacheTags::itemTags(CacheTags::VIDEOS, $id)
        );

        if (!$dto) {
            return $this->json(['message' => 'Vidéo non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }


    #[Route('', name: 'api_video_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] VideoInputDto $dto, Request $request): JsonResponse
    {
        $video = $this->createVideoUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/videos';
        return $this->json(new VideoOutputDto($video, $baseImageUrl), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_video_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] VideoInputDto $dto, Request $request): JsonResponse
    {
        $video = $this->updateVideoUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/videos';
        return $this->json(new VideoOutputDto($video));
    }

    #[Route('/{id}', name: 'api_video_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteVideoUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}