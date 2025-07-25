<?php
namespace App\UseCase\VideoUseCase;

use App\Services\VideoService\VideoService;

class GetAllVideosUseCase
{
    private VideoService $videoService;
    public function __construct(VideoService $videoService) { $this->videoService = $videoService; }
    public function execute(): array { return $this->videoService->getAllVideos(); }
}
