<?php
namespace App\Controller\VideoApiController;

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
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/videos')]
class VideoApiController extends AbstractController
{
    public function __construct(
        private GetAllVideosUseCase $getAllVideosUseCase,
        private CreateVideoUseCase $createVideoUseCase,
        private UpdateVideoUseCase $updateVideoUseCase,
        private DeleteVideoUseCase $deleteVideoUseCase,
        private TenantCacheService $cache,
        private GetVideoByIdUseCase $getVideoByIdUseCase
    ) {}

    #[Route('', name: 'api_video_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $cacheKey = 'videos_all_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/videos';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['videos_all']);

                return $this->getAllVideosUseCase->execute($baseImageUrl, $locale);
            }
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_video_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $cacheKey = 'video_' . $id . '_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/videos';

        $dto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['videos_all', 'video_' . $id]);

                return $this->getVideoByIdUseCase->execute($id, $baseImageUrl, $locale);
            }
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