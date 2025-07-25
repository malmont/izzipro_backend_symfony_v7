<?php
namespace App\UseCase\VideoUseCase;

use App\Dto\VideoInputDto;
use App\Entity\Video;
use App\Services\VideoService\VideoService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateVideoUseCase
{
    private VideoService $videoService;
    public function __construct(VideoService $videoService) { $this->videoService = $videoService; }
    public function execute(int $id, VideoInputDto $dto): Video
    {
        $video = $this->videoService->findVideo($id);
        if (!$video) { throw new NotFoundHttpException('Vidéo non trouvée.'); }
        return $this->videoService->updateVideo($video, $dto);
    }
}