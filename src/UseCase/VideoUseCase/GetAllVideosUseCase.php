<?php
namespace App\UseCase\VideoUseCase;

use App\Dto\VideoOutputDto; 
use App\Services\VideoService\VideoService;

class GetAllVideosUseCase
{
    private VideoService $videoService;
    public function __construct(VideoService $videoService) { $this->videoService = $videoService; }

    /**
     * @return VideoOutputDto[]
     */
    public function execute(string $baseImageUrl, string $locale): array 
    { 
        $videos = $this->videoService->getAllVideos();
        
        return array_map(
            fn($video) => new VideoOutputDto($video, $baseImageUrl, $locale),
            $videos
        );
    }
}