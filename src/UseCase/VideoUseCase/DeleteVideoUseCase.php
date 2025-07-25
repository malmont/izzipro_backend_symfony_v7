<?php
namespace App\UseCase\VideoUseCase;

use App\Services\VideoService\VideoService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteVideoUseCase
{
    private VideoService $videoService;
    public function __construct(VideoService $videoService) { $this->videoService = $videoService; }
    public function execute(int $id): void
    {
        $video = $this->videoService->findVideo($id);
        if (!$video) { throw new NotFoundHttpException('Vidéo non trouvée.'); }
        $this->videoService->deleteVideo($video);
    }
}